$pharFile = "phpDocumentor.phar"
$compareDt = (Get-Date).AddDays(-1)

if (!(test-path $pharFile -newerThan $compareDt))
{
    Write-Output "Downloading phpDocumentor as it was not found or is more than a day old..."
    Invoke-WebRequest https://www.phpdoc.org/phpDocumentor.phar -OutFile $pharFile
}

.\vendor\bin\wp-documentor parse --output=docs/wpApi/actions.rst --prefix=tp_ --type=actions --format=phpdocumentor-rst .\src\TouchPoint-WP\
.\vendor\bin\wp-documentor parse --output=docs/wpApi/filters.rst --prefix=tp_ --type=filters --format=phpdocumentor-rst .\src\TouchPoint-WP\

if (Test-Path .\docs\wpApi\index.rst)
{
    Remove-Item .\docs\wpApi\index.rst
}
New-Item .\docs\wpApi\index.rst -type File
Get-Content .\docs\wpApi\filters.rst, .\docs\wpApi\actions.rst | Out-File .\docs\wpApi\index.rst

php phpDocumentor.phar --template=responsive

#.\vendor\bin\phpdocmd docs/phpApi/structure.xml docs/api --lt %c --index _Sidebar.md

