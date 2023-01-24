$pharFile = "phpDocumentor.phar"
$compareDt = (Get-Date).AddDays(-1)

if (!(test-path $pharFile -newerThan $compareDt))
{
    Write-Output "Downloading phpDocumentor as it was not found or is more than a day old..."
    Invoke-WebRequest https://www.phpdoc.org/phpDocumentor.phar -OutFile $pharFile
}

php phpDocumentor.phar