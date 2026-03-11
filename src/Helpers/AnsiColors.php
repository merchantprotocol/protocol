<?php
namespace Gitcd\Helpers;

class AnsiColors
{
    // Brand colors (true color ANSI - matching merchantprotocol.com)
    public const G   = "\033[38;2;63;185;80m";    // #3FB950 green-bright
    public const GD  = "\033[38;2;46;160;67m";    // #2EA043 green-dim
    public const T   = "\033[38;2;230;237;243m";   // #e6edf3 text
    public const M   = "\033[38;2;139;148;158m";   // #8b949e muted
    public const D   = "\033[38;2;110;118;129m";   // #6e7681 dim
    public const B   = "\033[38;2;48;54;61m";      // #30363d border
    public const R   = "\033[38;2;255;95;87m";     // #ff5f57 red
    public const Y   = "\033[38;2;254;188;46m";    // #febc2e yellow
    public const GN  = "\033[38;2;40;200;64m";     // #28c840 green-dot
    public const BL  = "\033[38;2;121;192;255m";   // #79c0ff blue
    public const X   = "\033[0m";                   // reset
    public const BD  = "\033[1m";                   // bold
}
