<?php
namespace Gitcd;

use Symfony\Component\Console\Application;

class ProtocolApplication extends Application
{
    public function getHelp(): string
    {
        $version = $this->getVersion();

        return <<<LOGO

<fg=cyan>  ██████╗ ██████╗  ██████╗ ████████╗ ██████╗  ██████╗ ██████╗ ██╗</>
<fg=cyan>  ██╔══██╗██╔══██╗██╔═══██╗╚══██╔══╝██╔═══██╗██╔════╝██╔═══██╗██║</>
<fg=cyan>  ██████╔╝██████╔╝██║   ██║   ██║   ██║   ██║██║     ██║   ██║██║</>
<fg=cyan>  ██╔═══╝ ██╔══██╗██║   ██║   ██║   ██║   ██║██║     ██║   ██║██║</>
<fg=cyan>  ██║     ██║  ██║╚██████╔╝   ██║   ╚██████╔╝╚██████╗╚██████╔╝███████╗</>
<fg=cyan>  ╚═╝     ╚═╝  ╚═╝ ╚═════╝    ╚═╝    ╚═════╝  ╚═════╝ ╚═════╝ ╚══════╝</>

  <fg=white>Release-based deployment & infrastructure management</>
  <fg=white>for Docker applications.</>  <fg=yellow>v{$version}</>

  <fg=gray>Merchant Protocol · merchantprotocol.com</>

<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>

  <fg=yellow>Getting Started:</>

    <fg=green>protocol init</>              Set up a new or existing project
    <fg=green>protocol start</>             Start all services on this node
    <fg=green>protocol status</>            Check node health & services
    <fg=green>protocol release:create</>    Tag and publish a new release
    <fg=green>protocol deploy:push</> <fg=gray><version></>  Deploy a release to all nodes

  <fg=yellow>Migrate from v1 (branch-based):</>

    <fg=green>protocol migrate</>           Interactive migration wizard

<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>
LOGO;
    }
}
