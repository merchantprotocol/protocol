# Git Continuous Deployment Tool

Most continuous delivery pipelines are just been too complex. They're cumbersome to setup initially and even more complex when you introduce auto scaling worker nodes that are constantly changing.

I wanted something that was quick to setup, whether I was running a single node or 100. I needed something that could update new auto scaled worker nodes as soon as they came online, and something that kept the worker nodes constantly in sync.

My solution was to create a master/slave continuous deployment system. I have always lived by the idea that the MASTER branch should always be production ready. All of our work should be done on feature branches and only merged into master after all tests have passed and manual QA has been done. Therefore having a continuous deployment tool that builds worker nodes and keeps them in sync with the master repo was the ideal solution.

Once installed, any commits made to the master repo will immediately be replicated to the slave node, thanks to the Git Continuous Deployment Tool.

## Installation

```
# git clone https://github.com/merchantprotocol/github-continuous-deployment.git /opt/continuous-deployment
# /opt/continuous-deployment/bin/pipeline key:generate
# /opt/continuous-deployment/bin/pipeline git:clone <remote_repo_url> <public_html_dir>
# /opt/continuous-deployment/bin/pipeline repo:slave <public_html_dir> -d
```

## bin/pipeline --help

```
Console Tool

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  completion    Dump the shell completion script
  help          Display help for a command
  list          List commands
 git
  git:clone     Clone from remote repo
  git:pull      Pull from github and update the local repo
 key
  key:generate  Generate an openssl key
 repo
  repo:slave    Continuously deployment keeps the local repo updated with the remote changes
```
