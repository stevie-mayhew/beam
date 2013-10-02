<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Exception\RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Class Compiler
 * @package Heyday\Component\Beam
 */
class Compiler
{
    /**
     * The relative path to the project root
     */
    const ROOT_DIR = '/../../../..';
    /**
     * @var
     */
    protected $version;
    /**
     * @param  string            $pharFile
     * @return string
     * @throws RuntimeException
     */
    public function compile($pharFile = 'beam.phar')
    {
        if (!file_exists(__DIR__ . self::ROOT_DIR . '/composer.lock')) {
            throw new RuntimeException("Composer dependencies not installed");
        }
        
        $process = new Process('composer dump-autoload -o', __DIR__ . self::ROOT_DIR);
        
        if ($process->run() != 0) {
            throw new RuntimeException("Composer failed to dump the autoloader: " . $process->getErrorOutput());
        }

        $process = new Process('git log --pretty="%H" -n1 HEAD', __DIR__);
        if ($process->run() != 0) {
            throw new RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }
        $this->version = trim($process->getOutput());

        $process = new Process('git describe --tags HEAD');
        if ($process->run() == 0) {
            $this->version = trim($process->getOutput());
        }

        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $phar = new \Phar($pharFile, 0, $pharFile);
        $phar->setSignatureAlgorithm(\Phar::SHA1);
        $phar->startBuffering();

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in(__DIR__);

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $vendorDir = realpath(__DIR__ . self::ROOT_DIR . '/vendor');

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->exclude('Tests')
            ->in("$vendorDir/herzult/php-ssh/src/")
            ->in("$vendorDir/symfony/console/")
            ->in("$vendorDir/symfony/process/")
            ->in("$vendorDir/symfony/options-resolver/")
            ->in("$vendorDir/symfony/config/")
            ->in("$vendorDir/stecman/symfony-console-completion/src/");

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $this->addFile($phar, new \SplFileInfo("$vendorDir/autoload.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_namespaces.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_classmap.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_real.php"));
        if (file_exists("$vendorDir/composer/include_paths.php")) {
            $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/include_paths.php"));
        }
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/ClassLoader.php"));
        $this->addBin($phar);

        // Stubs
        $phar->setStub($this->getStub());
        $phar->stopBuffering();

        unset($phar);

        file_put_contents('beam.phar.version', trim($this->version));

        chmod($pharFile, 0755);

        return 'Success';
    }

    /**
     * @param $phar
     * @param $file
     */
    private function addFile($phar, $file)
    {
        $path = str_replace(realpath(__DIR__ . self::ROOT_DIR) . DIRECTORY_SEPARATOR, '', $file->getRealPath());

        $content = php_strip_whitespace($file);

        if (basename($path) !== 'SelfUpdateCommand.php') {
            $content = str_replace('~package_version~', $this->version, $content);
        }

        $phar->addFromString($path, $content);
        $phar[$path]->compress(\Phar::GZ);
    }

    /**
     * @param $phar
     */
    private function addBin($phar)
    {
        $content = php_strip_whitespace(__DIR__ . self::ROOT_DIR . '/bin/beam');
        $phar->addFromString('bin/beam', preg_replace('{^#!/usr/bin/env php\s*}', '', $content));
    }

    /**
     * @return string
     */
    private function getStub()
    {
        return <<<'EOF'
#!/usr/bin/env php
<?php
Phar::mapPhar('beam.phar');
require 'phar://beam.phar/bin/beam';
__HALT_COMPILER();
EOF;
    }
}
