@echo off
chcp 65001 >nul
echo [ODA Watch V2] Starting... (import: DWG→DXF, export: DXF→DWG)
echo [ODA Watch V2] Watching: D:\SHV_ERP\SHVQ_V2\CAD\cad_saves\
echo [ODA Watch V2] ODA Path: D:\SHV_ERP\tools\ODAFileConverter\
echo [ODA Watch V2] Press Ctrl+C to stop.

set ODA_EXE=D:\SHV_ERP\tools\ODAFileConverter\ODAFileConverter.exe
set CAD_SAVES=D:\SHV_ERP\SHVQ_V2\CAD\cad_saves

if not exist "%ODA_EXE%" (
  echo [ERROR] ODAFileConverter.exe not found at %ODA_EXE%
  pause
  exit /b 1
)

if not exist "%CAD_SAVES%\convert_in" mkdir "%CAD_SAVES%\convert_in"
if not exist "%CAD_SAVES%\convert_out" mkdir "%CAD_SAVES%\convert_out"
if not exist "%CAD_SAVES%\export_in" mkdir "%CAD_SAVES%\export_in"
if not exist "%CAD_SAVES%\export_out" mkdir "%CAD_SAVES%\export_out"

:loop
for %%f in ("%CAD_SAVES%\convert_in\*.dwg") do (
  echo [IMPORT] %%~nxf
  "%ODA_EXE%" "%CAD_SAVES%\convert_in" "%CAD_SAVES%\convert_out" ACAD2018 DXF 0 1
  del "%%f"
)

for %%f in ("%CAD_SAVES%\export_in\*.dxf") do (
  echo [EXPORT] %%~nxf
  "%ODA_EXE%" "%CAD_SAVES%\export_in" "%CAD_SAVES%\export_out" ACAD2018 DWG 0 1
  del "%%f"
)

ping -n 3 127.0.0.1 >nul
goto loop
