<?php
/**
 * Config-driven project initializer.
 *
 * Replaces individual Php81/Php82/Php82Ffmpeg classes with a single
 * data-driven class — add new project types by adding a config array,
 * not a new class file.
 */
namespace Gitcd\Commands\Init;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class ProjectType extends BaseInitializer
{
    private string $name;
    private string $description;
    private string $dockerImage;
    private string $gitHubRepo;
    private string $templateDir;

    public function __construct(array $config)
    {
        $this->name        = $config['name'];
        $this->description = $config['description'];
        $this->dockerImage = $config['docker_image'];
        $this->gitHubRepo  = $config['github_repo'];
        $this->templateDir = $config['template_dir'];
    }

    public function getName(): string        { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getDockerImage(): string { return $this->dockerImage; }
    public function getGitHubRepo(): string  { return $this->gitHubRepo; }
    public function getTemplateDir(): string { return $this->templateDir; }

    protected function initializeProject(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void
    {
        $this->generateDockerCompose($repo_dir, $input, $output, $helper);
    }

    /**
     * Registry of all available project types.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $baseDir = __DIR__;

        $types = [
            'php82' => [
                'name'         => 'PHP 8.2',
                'description'  => 'Nginx + PHP 8.2 FPM — SOC 2 ready, ModSecurity WAF',
                'docker_image' => 'byrdziak/merchantprotocol-webserver-nginx-php8.2-fpm:latest',
                'github_repo'  => 'https://github.com/merchantprotocol/docker-nginx-php8.2-fpm',
                'template_dir' => $baseDir . '/Php82',
            ],
            'php82ffmpeg' => [
                'name'         => 'PHP 8.2 + FFmpeg',
                'description'  => 'Nginx + PHP 8.2 FPM + FFmpeg, Whisper, Node.js',
                'docker_image' => 'byrdziak/merchantprotocol-webserver-nginx-php8.2-ffmpeg:latest',
                'github_repo'  => 'https://github.com/merchantprotocol/docker-nginx-php8.2-ffmpeg',
                'template_dir' => $baseDir . '/Php82Ffmpeg',
            ],
            'php81' => [
                'name'         => 'PHP 8.1',
                'description'  => 'Nginx + PHP 8.1 FPM',
                'docker_image' => 'byrdziak/merchantprotocol-webserver-nginx-php8.1:initial',
                'github_repo'  => 'https://github.com/merchantprotocol/docker-nginx-php8.1-fpm',
                'template_dir' => $baseDir . '/Php81',
            ],
        ];

        $instances = [];
        foreach ($types as $key => $config) {
            $instances[$key] = new self($config);
        }
        return $instances;
    }
}
