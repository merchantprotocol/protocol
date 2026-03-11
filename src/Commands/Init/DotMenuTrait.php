<?php
namespace Gitcd\Commands\Init;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive dot-menu with arrow key navigation.
 *
 * Renders options as colored dots. The selected option is green (●),
 * unselected are dim (○). Arrow keys move the selection, Enter confirms.
 * Falls back to numbered input in non-interactive mode.
 */
trait DotMenuTrait
{
    protected function askWithDots(
        InputInterface $input,
        OutputInterface $output,
        $helper,
        array $options,
        string $recommended
    ): string {
        $keys = array_keys($options);
        $labels = array_values($options);
        $count = count($keys);
        $selectedIndex = array_search($recommended, $keys);
        if ($selectedIndex === false) {
            $selectedIndex = 0;
        }

        // Non-interactive: fall back to default
        if (!$input->isInteractive()) {
            $this->renderDotMenu($output, $keys, $labels, $selectedIndex, $recommended);
            $output->writeln('');
            return $keys[$selectedIndex];
        }

        // Interactive: arrow key navigation
        $sttyState = trim(shell_exec('stty -g 2>/dev/null') ?: '');
        system('stty -echo -icanon min 1 2>/dev/null');

        $this->renderDotMenuRaw($keys, $labels, $selectedIndex, $recommended);
        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "    \033[90m↑↓ navigate · enter to select\033[0m");

        $stdin = fopen('php://stdin', 'r');

        while (true) {
            $char = fread($stdin, 1);

            if ($char === "\n" || $char === "\r") {
                break;
            }

            if ($char === "\033") {
                $seq = fread($stdin, 2);
                if ($seq === '[A') { // Up arrow
                    $selectedIndex = ($selectedIndex - 1 + $count) % $count;
                } elseif ($seq === '[B') { // Down arrow
                    $selectedIndex = ($selectedIndex + 1) % $count;
                }

                // Move cursor up to redraw menu (count lines + 1 for hint)
                fwrite(STDOUT, "\033[" . ($count + 1) . "A\r");
                fwrite(STDOUT, "\033[J");

                $this->renderDotMenuRaw($keys, $labels, $selectedIndex, $recommended);
                fwrite(STDOUT, "\n");
                fwrite(STDOUT, "    \033[90m↑↓ navigate · enter to select\033[0m");
            }
        }

        // Clear the hint line and redraw final state
        fwrite(STDOUT, "\033[" . ($count + 1) . "A\r");
        fwrite(STDOUT, "\033[J");

        // Restore terminal before final Symfony render
        if ($sttyState) {
            system("stty '{$sttyState}' 2>/dev/null");
        } else {
            system('stty echo icanon 2>/dev/null');
        }

        $this->renderDotMenu($output, $keys, $labels, $selectedIndex, $recommended);

        $output->writeln('');
        return $keys[$selectedIndex];
    }

    /**
     * Render the dot menu using raw ANSI codes (for interactive redraws)
     */
    protected function renderDotMenuRaw(
        array $keys,
        array $labels,
        int $selectedIndex,
        string $recommended
    ): void {
        foreach ($keys as $i => $key) {
            $label = $labels[$i];
            $isSelected = ($i === $selectedIndex);
            $isRecommended = ($key === $recommended);
            $recTag = $isRecommended ? '  recommended' : '';

            if ($isSelected) {
                fwrite(STDOUT, "    \033[32m●  {$label}\033[0m{$recTag}\n");
            } else {
                fwrite(STDOUT, "    \033[33m○\033[0m  \033[90m{$label}{$recTag}\033[0m\n");
            }
        }
    }

    /**
     * Render the dot menu display (Symfony formatter version for static renders)
     */
    protected function renderDotMenu(
        OutputInterface $output,
        array $keys,
        array $labels,
        int $selectedIndex,
        string $recommended
    ): void {
        foreach ($keys as $i => $key) {
            $label = $labels[$i];
            $isSelected = ($i === $selectedIndex);
            $isRecommended = ($key === $recommended);
            $recTag = $isRecommended ? '  recommended' : '';

            if ($isSelected) {
                $output->writeln("    <fg=green>●  {$label}</>{$recTag}");
            } else {
                $output->writeln("    <fg=yellow>○</>  <fg=gray>{$label}{$recTag}</>");
            }
        }
    }
}
