<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* layout/_topbar.html.twig */
class __TwigTemplate_2714319a4e4399a053cb3399e434d4ff extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 3
        yield "
<div class=\"top-bar relative\">
    <nav aria-label=\"breadcrumb\" class=\"-intro-x mr-auto hidden sm:flex\">
        <ol class=\"breadcrumb\">
            <li class=\"breadcrumb-item\"><a href=\"#\">Application</a></li>
            <li class=\"breadcrumb-item active\" aria-current=\"page\">";
        // line 8
        yield (((array_key_exists("pageTitle", $context) &&  !(null === $context["pageTitle"]))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["pageTitle"], "html", null, true)) : ("Dashboard"));
        yield "</li>
        </ol>
    </nav>

    ";
        // line 12
        if ((($tmp =  !($context["isCompanyScopedUser"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 13
            yield "    <div class=\"intro-x dropdown mr-4 sm:mr-6\">
        <div class=\"dropdown-toggle notification cursor-pointer\" role=\"button\" aria-expanded=\"false\" data-tw-toggle=\"dropdown\">
            <i data-lucide=\"settings\" class=\"notification__icon dark:text-slate-500\"></i>
        </div>
        <div class=\"notification-content pt-2 dropdown-menu\">
            <div class=\"notification-content__box dropdown-content w-[420px] max-w-full\">
                <div class=\"notification-content__title\">Servizi</div>
                <div class=\"relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition cursor-pointer\">
                    <div class=\"w-12 h-12 flex-none flex items-center justify-center mr-2\">
                        <i data-lucide=\"calculator\" class=\"w-6 h-6 text-slate-500\"></i>
                    </div>
                    <div class=\"flex-1 overflow-hidden\">
                        <div class=\"font-medium\">Calcola margini cantiere</div>
                        <div class=\"text-slate-500 text-sm mt-1\">Ricalcolo costi e margini BOB / Yard</div>
                        <div class=\"mt-1\">
                            <a href=\"#\" id=\"run-recalculate-margin\" class=\"text-blue-600 underline text-sm\">Avvia servizio</a>
                            <div id=\"recalculate-margin-result\" class=\"text-xs mt-1 hidden\"></div>
                        </div>
                    </div>
                </div>
                <div class=\"relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition cursor-pointer\">
                    <div class=\"w-12 h-12 flex-none flex items-center justify-center mr-2\">
                        <i data-lucide=\"database\" class=\"w-6 h-6 text-slate-500\"></i>
                    </div>
                    <div class=\"flex-1 overflow-hidden\">
                        <div class=\"font-medium\">Stato cantiere su Yard</div>
                        <div class=\"text-slate-500 text-sm mt-1\">Controlla stato su YARD</div>
                        <div class=\"mt-1\">
                            <a href=\"#\" id=\"run-yard-status\" class=\"text-blue-600 underline text-sm\">Avvia verifica</a>
                            <div id=\"yard-status-result\" class=\"text-xs mt-1 hidden\"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    ";
        }
        // line 50
        yield "
    <div class=\"intro-x dropdown mr-auto sm:mr-6\">
        <div id=\"notif-bell-toggle\" class=\"dropdown-toggle notification ";
        // line 52
        yield (((($tmp = ($context["unreadCount"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("notification--bullet") : (""));
        yield " cursor-pointer\" role=\"button\" aria-expanded=\"false\" data-tw-toggle=\"dropdown\">
            <i data-lucide=\"bell\" class=\"notification__icon dark:text-slate-500\"></i>
        </div>
        <div class=\"notification-content pt-2 dropdown-menu\">
            <div class=\"notification-content__box dropdown-content w-[450px] max-w-full\" id=\"notification-box\">
                <div class=\"notification-content__title flex items-center justify-between\">
                    <span>Notifiche</span>
                    <button id=\"open-history\" type=\"button\" data-tw-toggle=\"modal\" data-tw-target=\"#notification-history-modal\" class=\"ml-4 text-xs text-blue-600 underline\">Cronologia</button>
                </div>
                <div class=\"border-t border-slate-200 mt-4 pt-3\">
                    <button id=\"enable-browser-push\" type=\"button\" class=\"btn btn-sm btn-outline-primary w-full\">Attiva notifiche browser</button>
                    <div id=\"push-status\" class=\"text-xs text-slate-500 mt-2\"></div>
                </div>
                <div id=\"notification-list\">
                    ";
        // line 66
        if (Twig\Extension\CoreExtension::testEmpty(($context["notifications"] ?? null))) {
            // line 67
            yield "                        <div class=\"empty-notif text-slate-500 text-center p-4\">Nessuna notifica non letta</div>
                    ";
        } else {
            // line 69
            yield "                        ";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["notifications"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["notif"]) {
                // line 70
                yield "                            ";
                $context["profilePhoto"] = (((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "photo", [], "any", false, false, false, 70))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? (("/" . CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "photo", [], "any", false, false, false, 70))) : ("/uploads/avatar.jpg"));
                // line 71
                yield "                            <div class=\"notification-item relative flex items-start mt-5 p-2 hover:bg-slate-100 rounded transition\" data-id=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "id", [], "any", false, false, false, 71), "html", null, true);
                yield "\">
                                <div class=\"w-12 h-12 flex-none image-fit mr-2\">
                                    <img alt=\"Mittente\" class=\"rounded-full\" src=\"";
                // line 73
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["profilePhoto"] ?? null), "html", null, true);
                yield "\">
                                    <div class=\"w-3 h-3 bg-success absolute right-0 bottom-0 rounded-full border-2 border-white dark:border-darkmode-600\"></div>
                                </div>
                                <div class=\"flex-1 overflow-hidden\">
                                    <div class=\"flex items-center justify-between\">
                                        <span class=\"font-medium mr-2\">";
                // line 78
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::trim((((((CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "first_name", [], "any", true, true, false, 78) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "first_name", [], "any", false, false, false, 78)))) ? (CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "first_name", [], "any", false, false, false, 78)) : ("")) . " ") . (((CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "last_name", [], "any", true, true, false, 78) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "last_name", [], "any", false, false, false, 78)))) ? (CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "last_name", [], "any", false, false, false, 78)) : ("")))), "html", null, true);
                yield "</span>
                                        <div class=\"text-xs text-slate-400 whitespace-nowrap\">";
                // line 79
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFunction('date_fmt')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "created_at", [], "any", false, false, false, 79)), "html", null, true);
                yield "</div>
                                    </div>
                                    <div class=\"text-slate-500 mt-1 whitespace-normal break-words\">";
                // line 81
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "message", [], "any", false, false, false, 81), "html", null, true);
                yield "</div>
                                    <div class=\"mt-2 flex gap-3 text-xs\">
                                        <button type=\"button\" class=\"notif-mark-read text-emerald-700 underline\">Segna come letta</button>
                                        ";
                // line 84
                if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "link", [], "any", false, false, false, 84))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 85
                    yield "                                            <a href=\"";
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["notif"], "link", [], "any", false, false, false, 85), "html", null, true);
                    yield "\" class=\"notif-open text-blue-600 underline\">Apri</a>
                                        ";
                }
                // line 87
                yield "                                    </div>
                                </div>
                            </div>
                        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['notif'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 91
            yield "                    ";
        }
        // line 92
        yield "                </div>
            </div>
        </div>
    </div>

    <div class=\"intro-x dropdown w-8 h-8\">
        <div class=\"dropdown-toggle w-8 h-8 rounded-full overflow-hidden shadow-lg image-fit zoom-in\" role=\"button\" aria-expanded=\"false\" data-tw-toggle=\"dropdown\">
            <img alt=\"BOB\" src=\"";
        // line 99
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["currentUserPhoto"] ?? null), "html", null, true);
        yield "\">
        </div>
        <div class=\"dropdown-menu w-56\">
            <ul class=\"dropdown-content bg-primary text-white\">
                <li class=\"p-2\">
                    <div class=\"font-medium\">";
        // line 104
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["currentUserName"] ?? null), "html", null, true);
        yield "</div>
                    <div class=\"text-xs text-white/70 mt-0.5 dark:text-slate-500\">";
        // line 105
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["currentCompanyName"] ?? null), "html", null, true);
        yield "</div>
                </li>
                <li><hr class=\"dropdown-divider border-white/[0.08]\"></li>
                <li><a href=\"/profile\" class=\"dropdown-item hover:bg-white/5\"><i data-lucide=\"user\" class=\"w-4 h-4 mr-2\"></i> Profilo</a></li>
                <li><a href=\"/profile#password\" class=\"dropdown-item hover:bg-white/5\"><i data-lucide=\"lock\" class=\"w-4 h-4 mr-2\"></i> Cambia Password</a></li>
                ";
        // line 110
        if ((($context["currentUserId"] ?? null) == 1)) {
            // line 111
            yield "                <li><hr class=\"dropdown-divider border-white/[0.08]\"></li>
                <li class=\"px-3 py-2\">
                    <span class=\"text-xs font-semibold uppercase tracking-wide text-amber-300\">Super Admin</span>
                </li>
                <li><a href=\"/users/permissions\" class=\"dropdown-item hover:bg-white/5\"><i data-lucide=\"shield\" class=\"w-4 h-4 mr-2\"></i> Permissions</a></li>
                <li><a href=\"/users/notifications/send\" class=\"dropdown-item hover:bg-white/5\"><i data-lucide=\"megaphone\" class=\"w-4 h-4 mr-2\"></i> Invia Notifica</a></li>
                ";
        }
        // line 118
        yield "                <li><hr class=\"dropdown-divider border-white/[0.08]\"></li>
                <li><a href=\"/logout\" class=\"dropdown-item hover:bg-white/5\"><i data-lucide=\"toggle-right\" class=\"w-4 h-4 mr-2\"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</div>

<div id=\"notification-history-modal\" class=\"modal\" tabindex=\"-1\" aria-hidden=\"true\">
    <div class=\"modal-dialog modal-xl\">
        <div class=\"modal-content\">
            <div class=\"modal-header\">
                <h2 class=\"font-medium text-base mr-auto\">Cronologia notifiche lette</h2>
                <button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" data-tw-dismiss=\"modal\">Chiudi</button>
            </div>
            <div class=\"modal-body\">
                <div id=\"history-list\" class=\"max-h-[60vh] overflow-y-auto text-sm text-slate-700\">
                    <div class=\"text-slate-500\">Caricamento...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id=\"top-bar-config\"
     data-has-high-priority=\"";
        // line 142
        yield (((($tmp = ($context["hasHighPriority"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("1") : ("0"));
        yield "\"
     data-vapid-public-key=\"";
        // line 143
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["vapidPublicKey"] ?? null), "html", null, true);
        yield "\"
     hidden></div>
<script src=\"/assets/js/includes/template/top_bar.js\"></script>

<div id=\"priority-notif-modal\" style=\"display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;\">
    <div style=\"background:#fff;border-radius:18px;width:95%;max-width:560px;max-height:80vh;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.2);animation:pnm-in .3s ease;\">
        <div style=\"padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;\">
            <div style=\"width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#ef4444,#f97316);display:flex;align-items:center;justify-content:center;flex-shrink:0;\">
                <svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#fff\" stroke-width=\"2.5\"><path d=\"M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9\"/><path d=\"M13.73 21a2 2 0 01-3.46 0\"/></svg>
            </div>
            <div style=\"flex:1\">
                <h3 style=\"margin:0;font-size:16px;font-weight:800;color:#0f172a\">Notifiche Importanti</h3>
                <p style=\"margin:2px 0 0;font-size:12px;color:#64748b\">Richiedono la tua attenzione</p>
            </div>
            <button onclick=\"dismissPriorityModal()\" style=\"width:32px;height:32px;border-radius:8px;border:none;background:#f1f5f9;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;\">
                <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2.5\"><line x1=\"18\" y1=\"6\" x2=\"6\" y2=\"18\"/><line x1=\"6\" y1=\"6\" x2=\"18\" y2=\"18\"/></svg>
            </button>
        </div>
        <div id=\"priority-notif-list\" style=\"padding:16px 24px;overflow-y:auto;max-height:calc(80vh - 140px);display:flex;flex-direction:column;gap:10px;\"></div>
        <div style=\"padding:14px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;\">
            <button onclick=\"dismissPriorityModal()\" style=\"height:38px;padding:0 20px;border-radius:10px;border:none;background:linear-gradient(135deg,#312e81,#6366f1);color:#fff;font-size:13px;font-weight:700;cursor:pointer;\">Ho capito</button>
        </div>
    </div>
</div>
<link rel=\"stylesheet\" href=\"/assets/css/includes/template/top_bar.css\">
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "layout/_topbar.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  251 => 143,  247 => 142,  221 => 118,  212 => 111,  210 => 110,  202 => 105,  198 => 104,  190 => 99,  181 => 92,  178 => 91,  169 => 87,  163 => 85,  161 => 84,  155 => 81,  150 => 79,  146 => 78,  138 => 73,  132 => 71,  129 => 70,  124 => 69,  120 => 67,  118 => 66,  101 => 52,  97 => 50,  58 => 13,  56 => 12,  49 => 8,  42 => 3,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "layout/_topbar.html.twig", "/var/www/bob.csmontaggi.it/public/templates/layout/_topbar.html.twig");
    }
}
