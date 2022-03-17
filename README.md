# Git Continuous Deployment Tool

Most continuous delivery pipelines are just been too complex. They're cumbersome to setup initially and even more complex when you introduce auto scaling worker nodes that are constantly changing.

I wanted something that was quick to setup, whether I was running a single node or 100. I needed something that could update new auto scaled worker nodes as soon as they came online, and something that kept the worker nodes constantly in sync.

My solution was to create a master/slave continuous deployment system. I have always lived by the idea that the MASTER branch should always be production ready. All of our work should be done on feature branches and only merged into master after all tests have passed and manual QA has been done. Therefore having a continuous deployment tool that builds worker nodes and keeps them in sync with the master repo was the ideal solution.

Once installed, any commits made to the master repo will immediately be replicated to the slave node, thanks to the Git Continuous Deployment Tool.

## Installation

```
# git clone git@github.com:merchantprotocol/github-continuous-deployment.git /opt/continuous-deployment
# /opt/continuous-deployment/bin/pipeline key:generate
# /opt/continuous-deployment/bin/pipeline git:clone <remote_repo_url> <public_html_dir>
# /opt/continuous-deployment/bin/pipeline repo:slave <public_html_dir> -d
```

