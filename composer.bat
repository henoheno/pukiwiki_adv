@echo off
PATH = %PATH%;..\..\php54\;C:\xampp\php\;C:\Program Files (x86)\Git\bin

if not exist "composer.phar" (
	php -r "eval('?>'.file_get_contents('https://getcomposer.org/installer'));"
)

php composer.phar %*
