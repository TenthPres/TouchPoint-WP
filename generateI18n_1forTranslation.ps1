$pharFile = "wp-cli.phar"
$compareDt = (Get-Date).AddDays(-1)

if (!(test-path $pharFile -newerThan $compareDt))
{
    Write-Output "Downloading wp-cli as it was not found or is more than a day old..."
    Invoke-WebRequest https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -OutFile $pharFile
}

php .\wp-cli.phar i18n make-pot . i18n/TouchPoint-WP.pot
php .\wp-cli.phar i18n update-po i18n/TouchPoint-WP.pot