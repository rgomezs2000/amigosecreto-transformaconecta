<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Los diálogos del sistema deben vivir en una sola capa global.
 */
class DialogosTest extends TestCase
{
    public function test_los_dialogos_estan_centralizados_en_dialogos_js(): void
    {
        $central = public_path('js/dialogos.js');

        $this->assertFileExists($central);

        $contents = file_get_contents($central);

        $this->assertStringContainsString('window.AppDialog = AppDialog', $contents);
        $this->assertStringContainsString('window.bootbox.alert', $contents);
        $this->assertStringContainsString('window.bootbox.confirm', $contents);
        $this->assertStringContainsString('window.bootbox.prompt', $contents);

        foreach (glob(public_path('js/*.js')) as $file) {
            if (basename($file) === 'dialogos.js') {
                continue;
            }

            $script = file_get_contents($file);

            $this->assertStringNotContainsString('window.bootbox.', $script, basename($file));
            $this->assertStringNotContainsString('window.alert(', $script, basename($file));
            $this->assertStringNotContainsString('window.confirm(', $script, basename($file));
        }
    }
}
