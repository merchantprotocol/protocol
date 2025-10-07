# Protocol Init - Project Initializers

This directory contains project-specific initializers for the `protocol init` command.

## Architecture

### Interface: `ProjectInitializerInterface`
Defines the contract all project initializers must implement:
- `getName()` - Display name for the project type
- `getDescription()` - Description shown in selection menu
- `initialize($repo_dir, $output)` - Main initialization logic
- `getTemplateDir()` - Path to template files
- `createProtocolJson($repo_dir, $projectTypeKey, $output)` - Create protocol.json

### Base Class: `BaseInitializer`
Abstract base class that all initializers extend. Provides:

**Common Functionality:**
- `initialize()` - Orchestrates the initialization process
- `createProtocolJson()` - Creates protocol.json with git/docker metadata
- `initializeConfigRepo()` - Sets up configuration repository (optional)
- `createDirectory()` - Helper to create directories with feedback
- `copyFile()` - Helper to copy single files with feedback
- `copyFiles()` - Helper to copy multiple files with feedback

**Abstract Methods:**
- `initializeProject($repo_dir, $output)` - Project-specific logic (must implement)

**Configuration Repository:**
The base class includes `initializeConfigRepo()` which handles:
- Creating a separate git repo for environment-specific configs
- Setting up environment branches (localhost, staging, production)
- Cloning existing config repos or creating new ones
- Template file setup for new config repos
- Remote repository configuration

### Available Project Types

#### PHP 8.1 (`Php81.php`)
**Location:** `src/Commands/Init/Php81.php`
**Templates:** `src/Commands/Init/Php81/`

**What it creates:**
- `nginx.d/` directory with nginx configuration files
  - `nginx.conf` - HTTP server config with HTTPS redirect
  - `nginx-ssl.conf` - HTTPS server config
  - `php-fpm.conf` - PHP-FPM configuration
  - `php.ini` - PHP runtime configuration
- `cron.d/` directory for cron jobs
- `docker-compose.yml` - Docker Compose configuration

**Docker Image:** `byrdziak/merchantprotocol-webserver-nginx-php8.1:initial`

## Adding New Project Types

1. Create a new class in `src/Commands/Init/` (e.g., `Php82.php`)
2. Extend `BaseInitializer` abstract class
3. Implement required methods:
   - `getName()` - Return display name
   - `getDescription()` - Return description
   - `getTemplateDir()` - Return template directory path
   - `initializeProject()` - Your project-specific logic
4. Create a template directory (e.g., `src/Commands/Init/Php82/`)
5. Add template files to the directory
6. Register in `ProtocolInit::getAvailableInitializers()`:

```php
protected function getAvailableInitializers(): array
{
    return [
        'php81' => new Php81(),
        'php82' => new Php82(),  // Add your new initializer
    ];
}
```

### Example Initializer

```php
<?php
namespace Gitcd\Commands\Init;

use Symfony\Component\Console\Output\OutputInterface;

class Php82 extends BaseInitializer
{
    public function getName(): string
    {
        return 'PHP 8.2';
    }

    public function getDescription(): string
    {
        return 'PHP 8.2 with Nginx web server';
    }

    public function getTemplateDir(): string
    {
        return __DIR__ . '/Php82';
    }

    protected function initializeProject(string $repo_dir, OutputInterface $output): void
    {
        // Use base class helpers
        $this->createDirectory($repo_dir, 'nginx.d', $output);
        $this->createDirectory($repo_dir, 'cron.d', $output);
        
        // Copy files
        $this->copyFile(
            $this->getTemplateDir() . '/docker-compose.yml',
            $repo_dir . '/docker-compose.yml',
            'docker-compose.yml',
            $output
        );
        
        // Copy multiple files
        $this->copyFiles(
            $this->getTemplateDir() . '/nginx.d',
            $repo_dir . '/nginx.d',
            ['nginx.conf', 'nginx-ssl.conf'],
            'nginx.d',
            $output
        );
    }
}
```

## Usage

```bash
# Run init command
protocol init

# You will be prompted:
# "What kind of project are you setting up?"
#   [0] PHP 8.1 - PHP 8.1 with Nginx web server
#   > 0

# The selected initializer will:
# 1. Create required directories
# 2. Copy template files
# 3. Generate protocol.json with project_type
# 4. Optionally initialize configuration repository

# Initialize with config repo automatically
protocol init --with-config

# Initialize with specific environment
protocol init localhost --with-config
```

## Template Structure

Each project type should have its own directory with template files:

```
src/Commands/Init/
├── ProjectInitializerInterface.php
├── Php81.php
├── Php81/
│   ├── docker-compose.yml
│   └── nginx.d/
│       ├── nginx.conf
│       ├── nginx-ssl.conf
│       ├── php-fpm.conf
│       └── php.ini
└── README.md
```

## Generated protocol.json

The init command now adds a `project_type` field:

```json
{
    "name": "project-name",
    "project_type": "php81",
    "git": {
        "remote": "git@github.com/org/repo.git",
        "remotename": "origin",
        "branch": "master"
    },
    "docker": {
        "container_name": "app-container",
        "image": "byrdziak/merchantprotocol-webserver-nginx-php8.1:initial"
    }
}
```
