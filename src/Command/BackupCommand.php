<?php

namespace SWebhosting\GithubBackup\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Utils;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class BackupCommand extends Command
{
    private bool $cloneBare = false;

    private OutputInterface $output;

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
        $this->output = $output;

        $configFile = $input->getArgument('config');
        if (!file_exists($configFile)) {
            throw new RuntimeException(sprintf('The config file %s could not be found.', $configFile));
        }
        include($configFile);

        if (isset($cloneBare) && $cloneBare) {
            $this->cloneBare = true;
        }

        if (empty($requestUrl)) {
            throw new InvalidArgumentException('No $requestUrl is configured in the current config file.');
        }

        if (empty($backupDir)) {
            throw new InvalidArgumentException('No $backupDir is configured in the current config file.');
        }
        if (!is_dir($backupDir)) {
            throw new RuntimeException(
                sprintf('The configured $backupDir %s does not exist or is not a directory.', $backupDir)
            );
        }

        $client = new Client();
        $response = $client->request('GET', $requestUrl);
        $repositories = Utils::jsonDecode($response->getBody(), true);

        if (!is_array($repositories)) {
            throw new RuntimeException('JSON response of GitHub API did not return an array: ' . $response->getBody());
        }

        foreach ($repositories as $repositoryData) {
            $this->backupRepository($repositoryData, $backupDir);
        }
    }

    private function backupRepository(array $repodata, string $backupDir): void
    {
        $repoName = $this->getRepositoryPath($repodata);
        $cloneUrl = $this->getCloneUrl($repodata);
        $targetDir = $backupDir . DIRECTORY_SEPARATOR . $repoName;
        if ($this->cloneBare) {
            $targetDir .= '.git';
        }

        $subdirectory = dirname($repoName);
        if ($subdirectory) {
            $subdirectoryPath = $backupDir . DIRECTORY_SEPARATOR . $subdirectory;
            if (!is_dir($subdirectoryPath)) {
                mkdir($subdirectoryPath);
            }
        }

        $this->output->writeln('Backing up ' . $repoName, OutputInterface::VERBOSITY_DEBUG);

        $command = $this->buildGitCommand($cloneUrl, $targetDir);

        $process = Process::fromShellCommandline($command);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->output->writeln('The command "' . $command . '" failed.', OutputInterface::VERBOSITY_QUIET);
            $this->output->writeln($process->getErrorOutput());
        }
    }

    private function buildGitCommand(string $cloneUrl, string $targetDir): string
    {
        if (is_dir($targetDir)) {
            return $this->buildGitFetchCommand($targetDir);
        }

        $cloneCommand = 'git clone -q --mirror';
        if ($this->cloneBare) {
            $cloneCommand .= ' --bare';
        }
        $cloneCommand .= ' ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($targetDir);
        $this->output->writeln($cloneCommand, OutputInterface::VERBOSITY_DEBUG);
        return $cloneCommand;
    }

    private function buildGitFetchCommand(string $targetDir): string
    {
        $fetchCommand = 'cd ' . escapeshellarg($targetDir) . '; git fetch --all -q > /dev/null';
        $this->output->writeln($fetchCommand, OutputInterface::VERBOSITY_DEBUG);
        return $fetchCommand;
    }

    private function getCloneUrl(array $repodata)
    {
        if (!empty($repodata['clone_url'])) {
            return $repodata['clone_url'];
        }
        if (!empty($repodata['ssh_url_to_repo'])) {
            return $repodata['ssh_url_to_repo'];
        }
        throw new RuntimeException('The clone URL could not be determined in the current repo data.');
    }

    private function getRepositoryPath(array $repodata): string
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
        throw new RuntimeException('The repository path could not be determined in the current repo data.');
    }
}
