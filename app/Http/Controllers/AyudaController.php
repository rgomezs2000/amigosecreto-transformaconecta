<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\HelpArticle;
use App\Models\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Centro de ayuda: Acerca de, Guía de uso y preguntas frecuentes.
 *
 * Las tres pantallas son estáticas, pero su texto no vive en las vistas: se
 * lee de help_articles y de app_settings. Así el contenido se corrige o se
 * amplía sin tocar el código y la versión del sistema tiene un solo origen.
 */
class AyudaController extends Controller
{
    public function acercaDe(): View
    {
        $module = $this->modulo('acerca-de');

        return view('ayuda.acerca-de', $this->datos($module));
    }

    public function guia(): View
    {
        $module = $this->modulo('ayuda');

        return view('ayuda.guia', $this->datos($module));
    }

    public function faq(): View
    {
        $module = $this->modulo('faq');
        $datos = $this->datos($module);

        // Las preguntas se agrupan por tema, conservando el orden del maestro.
        // El bloque de administración se separa del contenido público.
        $datos['topics'] = $this->agruparPorTema($datos['articles']);
        $datos['adminTopics'] = $this->agruparPorTema($datos['adminArticles']);

        return view('ayuda.faq', $datos);
    }

    /**
     * Módulo activo al que pertenece la pantalla.
     */
    private function modulo(string $code): Module
    {
        $module = Module::activeByCode($code);

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        return $module;
    }

    /**
     * Datos comunes de las tres pantallas.
     *
     * El contenido de administración se consulta solo si la cuenta tiene rol
     * Administrador. Para un participante ni siquiera llega a la vista.
     *
     * @return array{
     *     module: Module,
     *     articles: Collection<int, HelpArticle>,
     *     adminArticles: Collection<int, HelpArticle>,
     *     isAdmin: bool,
     *     version: string,
     *     settings: array{name: string, tagline: string, status: string, notes: string}
     * }
     */
    private function datos(Module $module): array
    {
        $user = auth()->user();
        $isAdmin = (bool) $user?->isAdmin();
        $version = AppSetting::getValue('app.version', '0.1') ?? '0.1';

        return [
            'module' => $module,
            'articles' => $this->articulos($module, HelpArticle::AUDIENCE_ALL),
            'adminArticles' => $isAdmin
                ? $this->articulos($module, HelpArticle::AUDIENCE_ADMIN)
                : collect(),
            'isAdmin' => $isAdmin,
            'version' => $version,
            'settings' => [
                'name' => AppSetting::getValue('app.name', 'Amigo Secreto Inclusivo') ?? 'Amigo Secreto Inclusivo',
                'tagline' => AppSetting::getValue('app.tagline', 'Un intercambio claro, tranquilo y a tu ritmo.')
                    ?? 'Un intercambio claro, tranquilo y a tu ritmo.',
                'status' => AppSetting::getValue('app.version_status', 'Versión en curso') ?? 'Versión en curso',
                'notes' => AppSetting::getValue('app.version_notes', '') ?? '',
            ],
        ];
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    private function articulos(Module $module, string $audience): Collection
    {
        return HelpArticle::query()
            ->where('module_id', $module->id)
            ->published()
            ->forAudience($audience)
            ->ordered()
            ->get();
    }

    /**
     * @param  Collection<int, HelpArticle>  $articles
     * @return Collection<string, Collection<int, HelpArticle>>
     */
    private function agruparPorTema(Collection $articles): Collection
    {
        return $articles->groupBy(
            fn (HelpArticle $article) => filled($article->topic) ? $article->topic : 'Preguntas generales'
        );
    }
}
