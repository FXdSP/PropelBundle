<?php

/**
 * This file is part of the PropelBundle package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propel\PropelBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Util\Filesystem;

/**
 * Wrapper for Propel commands.
 *
 * @author Fabien Potencier <fabien.potencier@symfony-project.com>
 * @author William DURAND <william.durand1@gmail.com>
 */
abstract class AbstractPropelCommand extends ContainerAwareCommand
{
    /**
     * Additional Phing args to add in specialized commands.
     * @var array
     */
    protected $additionalPhingArgs = array();
    /**
     * Temporary XML schemas used on command execution.
     * @var array
     */
    protected $tempSchemas = array();
    /**
     * @var string
     */
    protected $cacheDir = null;
    /**
     * The Phing output.
     * @string
     */
    protected $buffer = null;

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->checkConfiguration();
    }

    /**
     * Call a Phing task.
     *
     * @param string $taskName  A Propel task name.
     * @param array $properties An array of properties to pass to Phing.
     */
    protected function callPhing($taskName, $properties = array())
    {
        $kernel = $this->getApplication()->getKernel();

        if (isset($properties['propel.schema.dir'])) {
            $this->cacheDir = $properties['propel.schema.dir'];
        } else {
            $this->cacheDir = $kernel->getRootDir().'/cache/' . $kernel->getEnvironment() . '/propel';

            $filesystem = new Filesystem();
            $filesystem->remove($this->cacheDir);
            $filesystem->mkdir($this->cacheDir);
        }

        $this->copySchemas($kernel, $this->cacheDir);

        // build.properties
        $this->createBuildPropertiesFile($kernel, $this->cacheDir.'/build.properties');

        // buidtime-conf.xml
        $this->createBuildTimeFile($this->cacheDir.'/buildtime-conf.xml');

        // Verbosity
        $bufferPhingOutput = $this->getContainer()->getParameter('kernel.debug');

        // Phing arguments
        $args = $this->getPhingArguments($kernel, $this->cacheDir, $properties);

        // Add any arbitrary arguments last
        foreach ($this->additionalPhingArgs as $arg) {
            if (in_array($arg, array('verbose', 'debug'))) {
                $bufferPhingOutput = false;
            }

            $args[] = '-'.$arg;
        }

        $args[] = $taskName;

        // Enable output buffering
        Phing::setOutputStream(new \OutputStream(fopen('php://output', 'w')));
        Phing::setErrorStream(new \OutputStream(fopen('php://output', 'w')));
        Phing::startup();
        Phing::setProperty('phing.home', getenv('PHING_HOME'));

        ob_start();

        $phing = new Phing();
        $returnStatus = true; // optimistic way

        try {
            $phing->execute($args);
            $phing->runBuild();

            $this->buffer = ob_get_contents();

            // Guess errors
            if (strstr($this->buffer, 'failed. Aborting.') ||
                strstr($this->buffer, 'Failed to execute') ||
                strstr($this->buffer, 'failed for the following reason:')) {
                $returnStatus = false;
            }
        } catch(\Exception $e) {
            $returnStatus = false;
        }

        if ($bufferPhingOutput) {
            ob_end_clean();
        } else {
            ob_end_flush();
        }

        return $returnStatus;
    }

    /**
     * Compiles arguments/properties for the Phing process.
     * @return array
     */
    private function getPhingArguments(KernelInterface $kernel, $workingDirectory, $properties)
    {
        $args = array();

        // Default properties
        $properties = array_merge(array(
            'propel.database'           => 'mysql',
            'project.dir'               => $workingDirectory,
            'propel.output.dir'         => $kernel->getRootDir().'/propel',
            'propel.php.dir'            => '/',
            'propel.packageObjectModel' => true,
        ), $properties);

        // Adding user defined properties from the configuration
        $properties = array_merge(
            $properties,
            $this->getContainer()->get('propel.build_properties')->getProperties()
        );

        foreach ($properties as $key => $value) {
            $args[] = "-D$key=$value";
        }

        // Build file
        $args[] = '-f';
        $args[] = realpath($this->getContainer()->getParameter('propel.path').'/generator/build.xml');

        return $args;
    }

    /**
     * @param KernelInterface $kernel   The application kernel.
     */
    protected function copySchemas(KernelInterface $kernel, $cacheDir)
    {
        $filesystem = new Filesystem();

        if (!is_dir($cacheDir)) {
            $filesystem->mkdir($cacheDir);
        }

        foreach ($kernel->getBundles() as $bundle) {
            if (is_dir($dir = $bundle->getPath().'/Resources/config')) {
                $finder  = new Finder();
                $schemas = $finder->files()->name('*schema.xml')->followLinks()->in($dir);

                $parts  = explode(DIRECTORY_SEPARATOR, realpath($bundle->getPath()));
                $length = count(explode('\\', $bundle->getNamespace())) * (-1);
                $prefix = implode('/', array_slice($parts, 1, $length));

                foreach ($schemas as $schema) {
                    $tempSchema = $bundle->getName().'-'.$schema->getBaseName();
                    $this->tempSchemas[$tempSchema] = array(
                        'bundle'    => $bundle->getName(),
                        'basename'  => $schema->getBaseName(),
                        'path'      => $schema->getPathname(),
                    );

                    $file = $cacheDir.DIRECTORY_SEPARATOR.$tempSchema;
                    $filesystem->copy((string) $schema, $file, true);

                    // the package needs to be set absolute
                    // besides, the automated namespace to package conversion has
                    // not taken place yet so it needs to be done manually
                    $database = simplexml_load_file($file);

                    if (isset($database['package'])) {
                        $database['package'] = $prefix . '/' . $database['package'];
                    } elseif (isset($database['namespace'])) {
                        $database['package'] = $prefix . '/' . str_replace('\\', '/', $database['namespace']);
                    } else {
                        throw new \RuntimeException(
                            sprintf('%s : Please define a `package` attribute or a `namespace` attribute for schema `%s`',
                                $bundle->getName(), $schema->getBaseName())
                        );
                    }

                    foreach ($database->table as $table) {
                        if (isset($table['package'])) {
                            $table['package'] = $prefix . '/' . $table['package'];
                        } elseif (isset($table['namespace'])) {
                            $table['package'] = $prefix . '/' . str_replace('\\', '/', $table['namespace']);
                        } else {
                            $table['package'] = $database['package'];
                        }
                    }

                    file_put_contents($file, $database->asXML());
                }
            }
        }
    }

    /**
     * Create a 'build.properties' file.
     *
     * @param KernelInterface $kernel   The application kernel.
     * @param string $file  Should be 'build.properties'.
     */
    protected function createBuildPropertiesFile(KernelInterface $kernel, $file)
    {
        $filesystem = new Filesystem();
        $buildPropertiesFile = $kernel->getRootDir().'/config/propel.ini';

        if (file_exists($buildPropertiesFile)) {
            $filesystem->copy($buildPropertiesFile, $file);
        } else {
            $filesystem->touch($file);
        }
    }

    /**
     * Create an XML file which represents propel.configuration
     *
     * @param string $file  Should be 'buildtime-conf.xml'.
     */
    protected function createBuildTimeFile($file)
    {
        $container = $this->getContainer();

        if (!$container->has('propel.configuration')) {
            throw new \InvalidArgumentException('Could not find Propel configuration.');
        }

        $xml = strtr(<<<EOT
<?xml version="1.0"?>
<config>
  <propel>
    <datasources default="%default_connection%">

EOT
        , array('%default_connection%' => $container->getParameter('propel.dbal.default_connection')));

        $propelConfiguration = $container->get('propel.configuration');
        foreach ($propelConfiguration['datasources'] as $name => $datasource) {
            $xml .= strtr(<<<EOT
      <datasource id="%name%">
        <adapter>%adapter%</adapter>
        <connection>
          <dsn>%dsn%</dsn>
          <user>%username%</user>
          <password>%password%</password>
        </connection>
      </datasource>

EOT
            , array(
                '%name%'     => $name,
                '%adapter%'  => $datasource['adapter'],
                '%dsn%'      => $datasource['connection']['dsn'],
                '%username%' => $datasource['connection']['user'],
                '%password%' => isset($datasource['connection']['password']) ? $datasource['connection']['password'] : '',
            ));
        }

        $xml .= <<<EOT
    </datasources>
  </propel>
</config>
EOT;

        file_put_contents($file, $xml);
    }

    /**
     * Returns an array of properties as key/value pairs from an input file.
     *
     * @param string $file  A file properties.
     * @return array        An array of properties as key/value pairs.
     */
    protected function getProperties($file)
    {
        $properties = array();

        if (false === $lines = @file($file)) {
            throw new \Exception(sprintf('Unable to parse contents of "%s".', $file));
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' == $line || in_array($line[0], array('#', ';'))) {
                continue;
            }

            $pos = strpos($line, '=');
            $properties[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }

        return $properties;
    }

    /**
     * Return the current Propel cache directory.
     * @return string   The current Propel cache directory.
     */
    protected function getCacheDir() {
        return $this->cacheDir;
    }

    /**
     * Get connection by checking the input option named 'connection'.
     * Returns the default connection if no option specified or an exception
     * if the specified connection doesn't exist.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throw \InvalidArgumentException If the connection does not exist.
     * @return array
     */
    protected function getConnection(InputInterface $input, OutputInterface $output) {
        $propelConfiguration = $this->getContainer()->get('propel.configuration');
        $name = $input->getOption('connection') ?: $this->getContainer()->getParameter('propel.dbal.default_connection');

        if (isset($propelConfiguration['datasources'][$name])) {
            $defaultConfig = $propelConfiguration['datasources'][$name];
        } else {
            throw new \InvalidArgumentException(sprintf('Connection named %s doesn\'t exist', $name));
        }

        $output->writeln(sprintf('Use connection named <comment>%s</comment> in <comment>%s</comment> environment.',
            $name, $this->getApplication()->getKernel()->getEnvironment()));

        return array($name, $defaultConfig);
    }

    /**
     * Extract the database name from a given DSN
     *
     * @param string $dsn   A DSN
     * @return string       The database name extracted from the given DSN
     */
    protected function parseDbName($dsn) {
        preg_match('#dbname=([a-zA-Z0-9\_]+)#', $dsn, $matches);
        return $matches[1];
    }

    /**
     * Check the PropelConfiguration object.
     */
    protected function checkConfiguration()
    {
        $parameters = $this->getContainer()->get('propel.configuration')->getParameters();

        if (!isset($parameters['datasources']) || 0 === count($parameters['datasources'])) {
            throw new \RuntimeException('Propel should be configured (no database configuration found).');
        }
    }

    /**
     * Write Propel output as summary based on a Regexp.
     *
     * @param OutputInterface $output   The output object.
     * @param string $taskname  A task name
     */
    protected function writeSummary(OutputInterface $output, $taskname)
    {
        foreach (explode("\n", $this->buffer) as $line) {
            if (false !== strpos($line, '[' . $taskname . ']')) {
                $arr  = preg_split('#\[' . $taskname . '\] #', $line);
                $info = $arr[1];

                if ('"' === $info[0]) {
                    $info = sprintf('<info>%s</info>', $info);
                }

                $output->writeln($info);
            }
        }
    }

    /**
     * Comes from the SensioGeneratorBundle.
     * @see https://github.com/sensio/SensioGeneratorBundle/blob/master/Command/Helper/DialogHelper.php#L52
     *
     * @param OutputInterface $output   The output.
     * @param string $text  A text message.
     * @param string $style A style to apply on the section.
     */
    protected function writeSection(OutputInterface $output, $text, $style = 'bg=blue;fg=white')
    {
        $output->writeln(array(
            '',
            $this->getHelperSet()->get('formatter')->formatBlock($text, $style, true),
            '',
        ));
    }

    /**
     * Renders an error message if a task has failed.
     *
     * @param OutputInterface $output   The output.
     * @param string $taskName  A task name.
	 * @param Boolean $more		Whether to add a 'more details' message or not.
     */
    protected function writeTaskError($output, $taskName, $more = true)
    {
        $moreText = $more ? ' To get more details, run the command with the "--verbose" option.' : '';

        return $this->writeSection($output, array(
            '[Propel] Error',
            '',
            'An error has occured during the "' . $taskName . '" task process.' . $moreText
        ), 'fg=white;bg=red');
    }

	/**
     * @param OutputInterface $output   The output.
	 * @param string $filename	The filename.
	 */
    protected function writeNewFile($output, $filename)
	{
		return $output->writeln('>>  <info>File+</info>    ' . $filename);
	}

    /**
     * Ask confirmation from the user.
     *
     * @param OutputInterface $output   The output.
     * @param string $question  A given question.
     * @param string $default   A default response.
     */
    protected function askConfirmation(OutputInterface $output, $question, $default = null)
    {
        return $this->getHelperSet()->get('dialog')->askConfirmation($output, $question, $default);
    }
}
