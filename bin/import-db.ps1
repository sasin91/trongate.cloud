# Local dev: drop and recreate the tcloud database from db/schema.sql.
$Schema = Join-Path $PSScriptRoot "..\db\schema.sql"

# Determine MySQL/MariaDB executable
$MysqlExe = "mysql"

# Helper to check command existence
function Test-Command ($Name) {
    return (Get-Command $Name -ErrorAction SilentlyContinue)
}

if (-not (Test-Command $MysqlExe)) {
    # If default "mysql" not found, check for "mariadb"
    if ($MysqlPath -eq "mysql" -and (Test-Command "mariadb")) {
        $MysqlExe = "mariadb"
    } else {
        # Check common locations
        $CommonPaths = @(
            "$env:USERPROFILE\.config\herd\bin\mysql.exe",
            "$env:USERPROFILE\.config\herd\bin\mariadb.exe",
            "C:\Program Files\MariaDB*\bin\mysql.exe",
            "C:\Program Files\MariaDB*\bin\mariadb.exe",
            "C:\Program Files\MySQL\MySQL Server*\bin\mysql.exe"
        )
        
        $Found = $false
        foreach ($Path in $CommonPaths) {
            # Handle wildcards in path by resolving
            $ResolvedPaths = $null
            try { $ResolvedPaths = Resolve-Path $Path -ErrorAction SilentlyContinue } catch {}
            
            if ($ResolvedPaths) {
                foreach ($RP in $ResolvedPaths) {
                    if (Test-Path $RP.Path) {
                        $MysqlExe = $RP.Path
                        $Found = $true
                        break
                    }
                }
            }
            if ($Found) { 
                Write-Host "Using $MysqlExe" -ForegroundColor Green
                break 
            }
        }
        
        if (-not $Found) {
            Write-Error "MySQL/MariaDB client not found in PATH or common locations. Please install it or provide path via -MysqlPath."
            exit 1
        }
    }
}

Write-Host "Recreating tcloud from $Schema..." -NoNewline

$Content = "DROP DATABASE IF EXISTS tcloud;`n" + (Get-Content $Schema -Raw)
$Output = $Content | & $MysqlExe -h localhost -u root 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host " [FAILED]" -ForegroundColor Red
    Write-Host $Output -ForegroundColor Yellow
    exit 1
}

Write-Host " [OK]" -ForegroundColor Green
