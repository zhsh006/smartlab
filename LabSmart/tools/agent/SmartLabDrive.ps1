# SmartLab "我的空间" launcher: find a Python that has Tkinter, then start the app.
$ErrorActionPreference = 'SilentlyContinue'
$app = Join-Path $PSScriptRoot 'smartlab-drive-app.py'

$candidates = @()
$where = Get-Command pythonw.exe -ErrorAction SilentlyContinue
if ($where) { $candidates += $where.Source }
$candidates += @(
  "$env:LOCALAPPDATA\Programs\Python\Python313\pythonw.exe",
  "$env:LOCALAPPDATA\Programs\Python\Python312\pythonw.exe",
  "$env:LOCALAPPDATA\Programs\Python\Python311\pythonw.exe",
  'C:\Python313\pythonw.exe',
  'C:\Python312\pythonw.exe',
  'C:\Python311\pythonw.exe',
  "$env:USERPROFILE\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\pythonw.exe"
)

$pythonw = $null
foreach ($candidate in $candidates) {
  if ($candidate -and (Test-Path $candidate)) {
    $python = Join-Path (Split-Path $candidate) 'python.exe'
    & $python -c "import tkinter" 2>$null
    if ($LASTEXITCODE -eq 0) { $pythonw = $candidate; break }
  }
}

if (-not $pythonw) {
  Add-Type -AssemblyName System.Windows.Forms
  [System.Windows.Forms.MessageBox]::Show('未找到带 Tkinter 的 Python。请先安装 Python 3（安装时勾选 tcl/tk）后重试。', 'SmartLab 我的空间')
  exit 1
}

Start-Process -FilePath $pythonw -ArgumentList ('"' + $app + '"')
