param(
    [string]$LocalesPath = (Join-Path $PSScriptRoot '..\locales')
)

function Convert-PoString {
    param([string]$Line)

    $start = $Line.IndexOf('"')
    if ($start -lt 0) {
        return ''
    }

    $json = $Line.Substring($start)
    return ConvertFrom-Json -InputObject $json
}

function Read-PoEntries {
    param([string]$Path)

    $entries = New-Object System.Collections.Generic.List[object]
    $entry = $null
    $field = $null

    foreach ($raw in Get-Content -LiteralPath $Path -Encoding UTF8) {
        $line = $raw.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) {
            if ($line -eq '' -and $null -ne $entry -and $null -ne $entry.msgid -and $null -ne $entry.msgstr) {
                if ($entry.msgid -ne '') {
                    $entries.Add($entry)
                }
                $entry = $null
                $field = $null
            }
            continue
        }

        if ($line.StartsWith('msgid ')) {
            if ($null -ne $entry -and $null -ne $entry.msgid -and $null -ne $entry.msgstr -and $entry.msgid -ne '') {
                $entries.Add($entry)
            }
            $entry = [pscustomobject]@{ msgid = (Convert-PoString $line); msgstr = '' }
            $field = 'msgid'
            continue
        }

        if ($line.StartsWith('msgstr ')) {
            if ($null -eq $entry) {
                $entry = [pscustomobject]@{ msgid = ''; msgstr = '' }
            }
            $entry.msgstr = Convert-PoString $line
            $field = 'msgstr'
            continue
        }

        if ($line.StartsWith('"') -and $null -ne $entry -and $null -ne $field) {
            $entry.$field += ConvertFrom-Json -InputObject $line
        }
    }

    if ($null -ne $entry -and $null -ne $entry.msgid -and $null -ne $entry.msgstr -and $entry.msgid -ne '') {
        $entries.Add($entry)
    }

    return $entries
}

function Write-UInt32LE {
    param(
        [byte[]]$Buffer,
        [int]$Offset,
        [long]$Value
    )

    $unsigned = if ($Value -lt 0) { [uint64]($Value + 4294967296) } else { [uint64]$Value }
    $Buffer[$Offset] = [byte]($unsigned -band 0xff)
    $Buffer[$Offset + 1] = [byte](($unsigned -shr 8) -band 0xff)
    $Buffer[$Offset + 2] = [byte](($unsigned -shr 16) -band 0xff)
    $Buffer[$Offset + 3] = [byte](($unsigned -shr 24) -band 0xff)
}

function Write-MoFile {
    param(
        [object[]]$Entries,
        [string]$Path
    )

    $utf8 = [System.Text.Encoding]::UTF8
    $sorted = $Entries | Sort-Object -Property msgid
    $originals = New-Object 'System.Collections.Generic.List[byte[]]'
    $translations = New-Object 'System.Collections.Generic.List[byte[]]'
    foreach ($entry in $sorted) {
        $originals.Add($utf8.GetBytes($entry.msgid))
        $translations.Add($utf8.GetBytes($entry.msgstr))
    }
    $count = $sorted.Count

    $headerSize = 28
    $originalTableOffset = $headerSize
    $translationTableOffset = $originalTableOffset + ($count * 8)
    $stringOffset = $translationTableOffset + ($count * 8)

    $originalRecords = New-Object System.Collections.Generic.List[object]
    foreach ($bytes in $originals) {
        $originalRecords.Add([pscustomobject]@{ Length = $bytes.Length; Offset = $stringOffset })
        $stringOffset += $bytes.Length + 1
    }

    $translationRecords = New-Object System.Collections.Generic.List[object]
    foreach ($bytes in $translations) {
        $translationRecords.Add([pscustomobject]@{ Length = $bytes.Length; Offset = $stringOffset })
        $stringOffset += $bytes.Length + 1
    }

    $buffer = New-Object byte[] $stringOffset
    Write-UInt32LE $buffer 0 0x950412de
    Write-UInt32LE $buffer 4 0
    Write-UInt32LE $buffer 8 $count
    Write-UInt32LE $buffer 12 $originalTableOffset
    Write-UInt32LE $buffer 16 $translationTableOffset
    Write-UInt32LE $buffer 20 0
    Write-UInt32LE $buffer 24 0

    $pos = $originalTableOffset
    foreach ($record in $originalRecords) {
        Write-UInt32LE $buffer $pos $record.Length
        Write-UInt32LE $buffer ($pos + 4) $record.Offset
        $pos += 8
    }

    $pos = $translationTableOffset
    foreach ($record in $translationRecords) {
        Write-UInt32LE $buffer $pos $record.Length
        Write-UInt32LE $buffer ($pos + 4) $record.Offset
        $pos += 8
    }

    for ($i = 0; $i -lt $count; $i++) {
        [Array]::Copy($originals[$i], 0, $buffer, $originalRecords[$i].Offset, $originals[$i].Length)
        [Array]::Copy($translations[$i], 0, $buffer, $translationRecords[$i].Offset, $translations[$i].Length)
    }

    [System.IO.File]::WriteAllBytes($Path, $buffer)
}

Get-ChildItem -LiteralPath $LocalesPath -Filter '*.po' | ForEach-Object {
    $entries = @(Read-PoEntries $_.FullName)
    $moPath = [System.IO.Path]::ChangeExtension($_.FullName, '.mo')
    Write-MoFile $entries $moPath
    Write-Host "$($_.Name): $($entries.Count) entries -> $(Split-Path $moPath -Leaf)"
}
