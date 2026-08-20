param(
    [Parameter(Mandatory = $true)]
    [string] $Directory
)

$ErrorActionPreference = 'Stop'
$manifestPath = Join-Path $Directory 'manifest.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$resavedDirectory = Join-Path $Directory 'resaved'
$extractedDirectory = Join-Path $Directory 'extracted'
New-Item -ItemType Directory -Force -Path $resavedDirectory, $extractedDirectory | Out-Null

if ($null -eq [type]::GetTypeFromProgID('Outlook.Application')) {
    throw 'Classic Outlook COM registration was not found. Install and activate Classic Outlook for the runner user.'
}

$outlook = $null
$namespace = $null
$failures = [System.Collections.Generic.List[string]]::new()

function Release-ComObject {
    param([object] $Object)

    if ($null -ne $Object -and [System.Runtime.InteropServices.Marshal]::IsComObject($Object)) {
        [void] [System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($Object)
    }
}

function Assert-OutlookItem {
    param(
        [Parameter(Mandatory = $true)] [object] $Namespace,
        [Parameter(Mandatory = $true)] [object] $Case,
        [Parameter(Mandatory = $true)] [string] $Path,
        [Parameter(Mandatory = $true)] [string] $Stage,
        [Parameter(Mandatory = $false)] [string] $SaveAsPath
    )

    $item = $null
    $attachments = $null
    $attachment = $null

    try {
        $item = $Namespace.OpenSharedItem($Path)
        if ($null -eq $item) {
            throw "Outlook returned no item for $Path"
        }

        if ([string] $item.Subject -ne [string] $Case.subject) {
            throw "${Stage}: subject changed"
        }

        if ($item.MessageClass -ne $Case.messageClass) {
            throw "${Stage}: message class changed from '$($Case.messageClass)' to '$($item.MessageClass)'"
        }

        $attachments = $item.Attachments
        if ($attachments.Count -ne 1) {
            throw "${Stage}: expected one attachment, Outlook reported $($attachments.Count)"
        }

        $attachment = $attachments.Item(1)
        if ($attachment.FileName -ne $Case.attachmentName) {
            throw "${Stage}: attachment name changed from '$($Case.attachmentName)' to '$($attachment.FileName)'"
        }

        $extractedPath = Join-Path $extractedDirectory "$($Case.file).$Stage.bin"
        $attachment.SaveAsFile($extractedPath)
        $actualHash = (Get-FileHash -LiteralPath $extractedPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actualHash -ne $Case.attachmentSha256) {
            throw "${Stage}: attachment payload hash changed"
        }

        if ($SaveAsPath) {
            # 9 = OlSaveAsType.olMSGUnicode
            $item.SaveAs($SaveAsPath, 9)
        }
    }
    finally {
        if ($null -ne $item) {
            try { $item.Close(1) } catch { Write-Warning "Unable to close Outlook item: $_" }
        }
        Release-ComObject $attachment
        Release-ComObject $attachments
        Release-ComObject $item
    }
}

try {
    $outlook = New-Object -ComObject Outlook.Application
    $namespace = $outlook.GetNamespace('MAPI')

    foreach ($case in @($manifest.cases)) {
        $inputPath = Join-Path $Directory $case.file
        $resavedPath = Join-Path $resavedDirectory $case.file

        try {
            Assert-OutlookItem -Namespace $namespace -Case $case -Path $inputPath -Stage 'generated' -SaveAsPath $resavedPath
            Assert-OutlookItem -Namespace $namespace -Case $case -Path $resavedPath -Stage 'resaved'
            Write-Host "PASS $($case.source)"
        }
        catch {
            $message = "$($case.source): $($_.Exception.Message)"
            $failures.Add($message)
            Write-Warning $message
        }
    }
}
finally {
    if ($null -ne $namespace) {
        try { $namespace.Logoff() } catch { Write-Warning "Unable to log off Outlook namespace: $_" }
    }
    if ($null -ne $outlook) {
        try { $outlook.Quit() } catch { Write-Warning "Unable to quit Outlook: $_" }
    }
    Release-ComObject $namespace
    Release-ComObject $outlook
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}

if ($failures.Count -gt 0) {
    throw "Classic Outlook rejected $($failures.Count) MSG file(s):`n- $($failures -join "`n- ")"
}

Write-Host "Classic Outlook opened, extracted, saved, and reopened $(@($manifest.cases).Count) MSG files."
