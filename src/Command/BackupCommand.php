<?php

namespace SWebhosting\GithubBackup\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class BackupCommand extends Command
{
    /**
     * @param array $repodata
     * @param string $backupDir
     * @param OutputInterface $output
     */
    protected function backupRepository(array $repodata, $backupDir, OutputInterface $output)
    {
        $repoName = $this->getRepositoryPath($repodata);
        $cloneUrl = $this->getCloneUrl($repodata);
        $targetDir = $backupDir . DIRECTORY_SEPARATOR . $repoName;

        $subdirectory = dirname($repoName);
        if ($subdirectory) {
            $subdirectoryPath = $backupDir . DIRECTORY_SEPARATOR . $subdirectory;
            if (!is_dir($subdirectoryPath)) {
                mkdir($subdirectoryPath);
            }
        }

        $output->writeln('Backing up ' . $repoName, OutputInterface::VERBOSITY_DEBUG);

        if (is_dir($targetDir)) {
            $fetchCommand = 'cd ' . escapeshellarg($targetDir) . '; git fetch --all -q > /dev/null';
            $output->writeln($fetchCommand, OutputInterface::VERBOSITY_DEBUG);
            $command = $fetchCommand;
        } else {
            $cloneCommand = 'git clone -q --mirror ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($targetDir);
            $output->writeln($cloneCommand, OutputInterface::VERBOSITY_DEBUG);
            $command = $cloneCommand;
        }

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln('The command "' . $command . '" failed.', OutputInterface::VERBOSITY_QUIET);
            $output->writeln($process->getErrorOutput());
        }
    }

    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Create / update a GitHub backup')
            ->addArgument(
                'config',
                InputArgument::REQUIRED,
                'The path to the config file.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('config');
        if (!file_exists($configFile)) {
            throw new \RuntimeException(sprintf('The config file %s could not be found.', $configFile));
        }
        /** @noinspection PhpIncludeInspection */
        include($configFile);

        if (empty($requestUrl)) {
            throw new \InvalidArgumentException('No $requestUrl is configured in the current config file.');
        }

        if (empty($backupDir)) {
            throw new \InvalidArgumentException('No $backupDir is configured in the current config file.');
        } elseif (!is_dir($backupDir)) {
            throw new \RuntimeException(
                sprintf('The configured $backupDir %s does not exist or is not a directory.', $backupDir)
            );
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request('GET', $requestUrl);
        $repositories = json_decode($response->getBody(), true);

        if (!$repositories || !is_array($repositories)) {
            throw new \RuntimeException('Error decoding JSON response of GitHub API.');
        }

        foreach ($repositories as $repositoryData) {
            $this->backupRepository($repositoryData, $backupDir, $output);
        }
    }

    protected function getCloneUrl(array $repodata)
    {
        if (!empty($repodata['clone_url'])) {
            return $repodata['clone_url'];
        }
        if (!empty($repodata['ssh_url_to_repo'])) {
            return $repodata['ssh_url_to_repo'];
        }
        throw new \RuntimeException('The clone URL could not be determined in the current repo data.');
    }

    protected function getRepositoryPath(array $repodata)
    {
        if (!empty($repodata['path_with_namespace'])) {
            return $repodata['path_with_namespace'];
        }
        if (!empty($repodata['path'])) {
            return $repodata['path'];
        }
        if (!empty($repodata['name'])) {
            return $repodata['name'];
        }
        throw new \RuntimeException('The repository path could not be determined in the current repo data.');
    }
}
