## Protocol the Command Line Tool

It just takes too long to repetitvely setup a new web server and then setup continuous deployment. Setting up a new server is easy, sure, but I don't want to waste my precious day setting up a new server and app if I don't have to. 

Then if I want continuous deployment.. there are lots of tools, but all are super complex for my needs, therefore taking way too long to setup. I want something simple, fast and reliable.

Oh and then don't forget having a VPS go down and losing your configuration files! Then you've got to set it all up again, not to forget trying to remember what the configurations were...

I'm done with those days!

Introducing Protocol, the Command Line Tool for PHP web apps.

With this tool you can quickly configure any php repository to deploy with ease on your server. Enable slave mode and all updates to your repository branch will get pushed out to production. It also manages the configuration files for multiple environments in a separate repository, making it easy to push configuration changes to hundreds of nodes.

This tool is becomming a valuable asset in my DevOps arsenal.

- It's perfect for setting up continuous deployment or continuous integration pipelines.
- Makes it easy to keep hundreds of nodes up to date in a cluster, even surviving a reboot!
- Manage production, staging and local development configuration files with ease.
- Makes it easy for new developers to the project to get up and running with little effort.
- Save time launching new web servers and applications

### Getting Started Quick Start Guide

In this quick start tutorial I'm going to show you how to deploy simple HelloWorld.php file to a production web app on your local machine.

#### 1. Installation

Open terminal and run the installation command:

```
sudo curl -L "https://raw.githubusercontent.com/merchantprotocol/protocol/master/bin/install" | bash
```

This will install all dependencies and the protocol command line tool on MacOS or Ubuntu.

You should now be able to run the following command to confirm that it's been installed

```
protocol -v
```

#### 2. Create A New Project Folder

If you already have an application in mind, then skip this step.

```
mkdir helloworld
cd helloworld
``

Add an index.php script

```
cat "<?php phpinfo();" > index.php
```

#### 3. Configure Your Project With Protocol






### Support or Contact

Having trouble with Protocol? Contact us as [MerchantProtocol.com](https://merchantprotocol.com/) and we’ll help you sort it out.
