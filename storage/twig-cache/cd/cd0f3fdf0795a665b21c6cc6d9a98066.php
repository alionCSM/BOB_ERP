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

/* dashboard/partials/admin.html.twig */
class __TwigTemplate_c206a5538ec4b30d3bc37bab3dc3b4cf extends Template
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
        // line 1
        yield "<link rel=\"stylesheet\" href=\"/assets/css/views/dashboard/partials/dashboard_admin.css\">

<div class=\"da-page\">

    <!-- ── HERO ────────────────────────────────── -->
    <div class=\"da-hero\">
        <div class=\"da-hero-content\">
            <h1 class=\"da-greeting\">";
        // line 8
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["greeting"] ?? null), "html", null, true);
        yield ", ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["name"] ?? null), "html", null, true);
        yield "</h1>
            <p class=\"da-date\">";
        // line 9
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["today"] ?? null), "html", null, true);
        yield "</p>
        </div>
    </div>

    <!-- ── STAT CARDS ──────────────────────────── -->
    <div class=\"da-stats\">
        <div class=\"da-stat\">
            <div class=\"da-stat-icon\" style=\"background:#eff6ff;\">
                <svg width=\"22\" height=\"22\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#2563eb\" stroke-width=\"2\"><path d=\"M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M23 21v-2a4 4 0 00-3-3.87\"/><path d=\"M16 3.13a4 4 0 010 7.75\"/></svg>
            </div>
            <div>
                <div class=\"da-stat-value\">";
        // line 20
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["totalUsers"] ?? null), "html", null, true);
        yield "</div>
                <div class=\"da-stat-label\">Utenti registrati</div>
                <div class=\"da-stat-sub\">";
        // line 22
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["activeUsers"] ?? null), "html", null, true);
        yield " attivi</div>
            </div>
        </div>
        <div class=\"da-stat\">
            <div class=\"da-stat-icon\" style=\"background:#fef3c7;\">
                <svg width=\"22\" height=\"22\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#d97706\" stroke-width=\"2\"><path d=\"M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4\"/></svg>
            </div>
            <div>
                <div class=\"da-stat-value\">";
        // line 30
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["activeWorksites"] ?? null), "html", null, true);
        yield "</div>
                <div class=\"da-stat-label\">Cantieri attivi</div>
                <div class=\"da-stat-sub\">";
        // line 32
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["totalWorksites"] ?? null), "html", null, true);
        yield " totali</div>
            </div>
        </div>
        <div class=\"da-stat\">
            <div class=\"da-stat-icon\" style=\"background:#ecfdf5;\">
                <svg width=\"22\" height=\"22\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#059669\" stroke-width=\"2\"><path d=\"M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4\"/></svg>
            </div>
            <div>
                <div class=\"da-stat-value\">";
        // line 40
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["todayAttendance"] ?? null), "html", null, true);
        yield "</div>
                <div class=\"da-stat-label\">Presenze oggi</div>
                <div class=\"da-stat-sub\">";
        // line 42
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["todayNostri"] ?? null), "html", null, true);
        yield " nostri + ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["todayCons"] ?? null), "html", null, true);
        yield " cons.</div>
            </div>
        </div>
        <div class=\"da-stat\">
            <div class=\"da-stat-icon\" style=\"background:";
        // line 46
        yield (((($context["expiredDocs"] ?? null) > 0)) ? ("#fef2f2") : ((((($context["expiringDocs"] ?? null) > 0)) ? ("#fffbeb") : ("#ecfdf5"))));
        yield ";\">
                <svg width=\"22\" height=\"22\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"";
        // line 47
        yield (((($context["expiredDocs"] ?? null) > 0)) ? ("#dc2626") : ((((($context["expiringDocs"] ?? null) > 0)) ? ("#d97706") : ("#059669"))));
        yield "\" stroke-width=\"2\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/><path d=\"M12 18v-6\"/><path d=\"M12 12l-2 2\"/><path d=\"M12 12l2 2\"/></svg>
            </div>
            <div>
                <div class=\"da-stat-value\" style=\"color:";
        // line 50
        yield (((($context["expiredDocs"] ?? null) > 0)) ? ("#dc2626") : ("#0f172a"));
        yield ";\">";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["expiredDocs"] ?? null), "html", null, true);
        yield "</div>
                <div class=\"da-stat-label\">Documenti scaduti</div>
                <div class=\"da-stat-sub\">";
        // line 52
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["expiringDocs"] ?? null), "html", null, true);
        yield " in scadenza (30gg)</div>
            </div>
        </div>
    </div>

    <!-- ── SYSTEM STATUS + SERVER RESOURCES ──── -->
    <div class=\"da-grid-2\">

        <!-- System Status -->
        <div class=\"da-section\">
            <div class=\"da-section-head\">
                <h3 class=\"da-section-title\">
                    <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#6366f1\" stroke-width=\"2\"><path d=\"M22 12h-4l-3 9L9 3l-3 9H2\"/></svg>
                    Stato Sistema
                </h3>
            </div>
            <div class=\"da-section-body\">
                <div class=\"da-status-row\">
                    <span class=\"da-status-label\">
                        <span class=\"da-status-dot ";
        // line 71
        yield (((($context["dbStatus"] ?? null) == "Online")) ? ("da-dot-green") : ("da-dot-red"));
        yield "\"></span>
                        Database MySQL
                    </span>
                    <span class=\"da-status-val\">";
        // line 74
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["dbStatus"] ?? null), "html", null, true);
        yield "</span>
                </div>
                <div class=\"da-status-row\">
                    <span class=\"da-status-label\">
                        <span class=\"da-status-dot ";
        // line 78
        yield (((($context["mailStatus"] ?? null) == "Operativo")) ? ("da-dot-green") : ((((($context["mailStatus"] ?? null) == "Non configurato")) ? ("da-dot-gray") : ("da-dot-red"))));
        yield "\"></span>
                        Mail Service
                    </span>
                    <span class=\"da-status-val\">";
        // line 81
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["mailStatus"] ?? null), "html", null, true);
        yield "</span>
                </div>
                <div class=\"da-status-row\">
                    <span class=\"da-status-label\">
                        <span class=\"da-status-dot ";
        // line 85
        yield (((($context["storageStatus"] ?? null) == "Online")) ? ("da-dot-green") : ((((($context["storageStatus"] ?? null) == "Sola lettura")) ? ("da-dot-yellow") : ("da-dot-red"))));
        yield "\"></span>
                        Cloud Storage (NFS)
                    </span>
                    <span class=\"da-status-val\">
                        ";
        // line 89
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["storageStatus"] ?? null), "html", null, true);
        if ((($tmp =  !(null === ($context["storageLatency"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield " <span style=\"color:#94a3b8;font-weight:400;\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["storageLatency"] ?? null), "html", null, true);
            yield "ms</span>";
        }
        // line 90
        yield "                    </span>
                </div>
                <div class=\"da-status-row\">
                    <span class=\"da-status-label\">
                        <span class=\"da-status-dot da-dot-green\"></span>
                        PHP Runtime
                    </span>
                    <span class=\"da-status-val\">";
        // line 97
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["phpMemoryUsage"] ?? null), "html", null, true);
        yield " / ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["phpMemoryLimit"] ?? null), "html", null, true);
        yield "</span>
                </div>
            </div>
        </div>

        <!-- Server Resources -->
        <div class=\"da-section\">
            <div class=\"da-section-head\">
                <h3 class=\"da-section-title\">
                    <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#6366f1\" stroke-width=\"2\"><rect x=\"2\" y=\"2\" width=\"20\" height=\"8\" rx=\"2\"/><rect x=\"2\" y=\"14\" width=\"20\" height=\"8\" rx=\"2\"/><line x1=\"6\" y1=\"6\" x2=\"6.01\" y2=\"6\"/><line x1=\"6\" y1=\"18\" x2=\"6.01\" y2=\"18\"/></svg>
                    Risorse Server
                </h3>
            </div>
            <div class=\"da-section-body\">
                <!-- CPU -->
                <div class=\"da-status-row\">
                    <span class=\"da-status-label\">CPU Load</span>
                    <span class=\"da-status-val\" style=\"color:";
        // line 114
        yield (((($context["cpuLoad"] ?? null) > 4)) ? ("#dc2626") : ((((($context["cpuLoad"] ?? null) > 2)) ? ("#d97706") : ("#059669"))));
        yield ";\">";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["cpuLoad"] ?? null), "html", null, true);
        yield "</span>
                </div>
                <!-- RAM -->
                <div style=\"padding: 8px 0;\">
                    <div style=\"display:flex;justify-content:space-between;margin-bottom:6px;\">
                        <span style=\"font-size:13px;color:#475569;\">RAM</span>
                        <span style=\"font-size:13px;font-weight:600;color:#1e293b;\">";
        // line 120
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["ramPercent"] ?? null), "html", null, true);
        yield "%</span>
                    </div>
                    <div class=\"da-progress-bg\">
                        <div class=\"da-progress-fill\" style=\"width:";
        // line 123
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["ramPercent"] ?? null), "html", null, true);
        yield "%;background:";
        yield (((($context["ramPercent"] ?? null) > 85)) ? ("#dc2626") : ((((($context["ramPercent"] ?? null) > 60)) ? ("#d97706") : ("#6366f1"))));
        yield ";\"></div>
                    </div>
                    <div class=\"da-progress-info\"><span>";
        // line 125
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["ramUsedGB"] ?? null), "html", null, true);
        yield " GB usati</span><span>";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["ramTotalGB"] ?? null), "html", null, true);
        yield " GB totali</span></div>
                </div>
                <!-- Disk -->
                <div style=\"padding: 8px 0;\">
                    <div style=\"display:flex;justify-content:space-between;margin-bottom:6px;\">
                        <span style=\"font-size:13px;color:#475569;\">Disco Server</span>
                        <span style=\"font-size:13px;font-weight:600;color:#1e293b;\">";
        // line 131
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["diskPercent"] ?? null), "html", null, true);
        yield "%</span>
                    </div>
                    <div class=\"da-progress-bg\">
                        <div class=\"da-progress-fill\" style=\"width:";
        // line 134
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["diskPercent"] ?? null), "html", null, true);
        yield "%;background:";
        yield (((($context["diskPercent"] ?? null) > 85)) ? ("#dc2626") : ((((($context["diskPercent"] ?? null) > 60)) ? ("#d97706") : ("#6366f1"))));
        yield ";\"></div>
                    </div>
                    <div class=\"da-progress-info\"><span>";
        // line 136
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["diskUsedGB"] ?? null), "html", null, true);
        yield " GB usati</span><span>";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["diskTotalGB"] ?? null), "html", null, true);
        yield " GB totali</span></div>
                </div>
                ";
        // line 138
        if ((($tmp =  !(null === ($context["cloudPercent"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 139
            yield "                <!-- Cloud -->
                <div style=\"padding: 8px 0 0;\">
                    <div style=\"display:flex;justify-content:space-between;margin-bottom:6px;\">
                        <span style=\"font-size:13px;color:#475569;\">Cloud NFS</span>
                        <span style=\"font-size:13px;font-weight:600;color:#1e293b;\">";
            // line 143
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["cloudPercent"] ?? null), "html", null, true);
            yield "%</span>
                    </div>
                    <div class=\"da-progress-bg\">
                        <div class=\"da-progress-fill\" style=\"width:";
            // line 146
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["cloudPercent"] ?? null), "html", null, true);
            yield "%;background:";
            yield (((($context["cloudPercent"] ?? null) > 85)) ? ("#dc2626") : ((((($context["cloudPercent"] ?? null) > 60)) ? ("#d97706") : ("#6366f1"))));
            yield ";\"></div>
                    </div>
                    <div class=\"da-progress-info\"><span>";
            // line 148
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["cloudUsedGB"] ?? null), "html", null, true);
            yield " GB usati</span><span>";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["cloudTotalGB"] ?? null), "html", null, true);
            yield " GB totali</span></div>
                </div>
                ";
        }
        // line 151
        yield "            </div>
        </div>

    </div>

    <!-- ── USER ANALYTICS ──────────────────────── -->
    <div class=\"da-grid-3\">

        <!-- Online Users -->
        <div class=\"da-section\">
            <div class=\"da-section-head\">
                <h3 class=\"da-section-title\">
                    <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#22c55e\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M8 14s1.5 2 4 2 4-2 4-2\"/><line x1=\"9\" y1=\"9\" x2=\"9.01\" y2=\"9\"/><line x1=\"15\" y1=\"9\" x2=\"15.01\" y2=\"9\"/></svg>
                    Utenti online
                </h3>
                <span class=\"da-section-badge\" id=\"online-count\" style=\"background:#ecfdf5;color:#059669;\">";
        // line 166
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["onlineCount"] ?? null), "html", null, true);
        yield "</span>
            </div>
            <div class=\"da-section-body\" id=\"online-users\" style=\"max-height:320px;overflow-y:auto;\">
                ";
        // line 169
        if ((($tmp =  !($context["onlineUsers"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 170
            yield "                    <div class=\"da-empty\">Nessun utente online</div>
                ";
        }
        // line 172
        yield "                ";
        $context["uColors"] = ["#6366f1", "#0ea5e9", "#8b5cf6", "#ec4899", "#14b8a6", "#f59e0b", "#ef4444", "#22c55e"];
        // line 173
        yield "                ";
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["onlineUsers"] ?? null));
        $context['loop'] = [
          'parent' => $context['_parent'],
          'index0' => 0,
          'index'  => 1,
          'first'  => true,
        ];
        if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
            $length = count($context['_seq']);
            $context['loop']['revindex0'] = $length - 1;
            $context['loop']['revindex'] = $length;
            $context['loop']['length'] = $length;
            $context['loop']['last'] = 1 === $length;
        }
        foreach ($context['_seq'] as $context["_key"] => $context["u"]) {
            // line 174
            yield "                    ";
            $context["uInit"] = Twig\Extension\CoreExtension::upper($this->env->getCharset(), Twig\Extension\CoreExtension::slice($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["u"], "first_name", [], "any", false, false, false, 174), 0, 1));
            // line 175
            yield "                    ";
            $context["uColor"] = (($_v0 = ($context["uColors"] ?? null)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[(CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, false, 175) % Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["uColors"] ?? null)))] ?? null) : null);
            // line 176
            yield "                    <div class=\"da-user-row\">
                        <div class=\"da-user-avatar\" style=\"background:";
            // line 177
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["uColor"] ?? null), "html", null, true);
            yield ";\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["uInit"] ?? null), "html", null, true);
            yield "</div>
                        <div style=\"flex:1;min-width:0;\">
                            <div class=\"da-user-name\">";
            // line 179
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["u"], "first_name", [], "any", false, false, false, 179), "html", null, true);
            yield "</div>
                            <div class=\"da-user-page\">";
            // line 180
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFilter('friendly_page')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["u"], "page", [], "any", false, false, false, 180)), "html", null, true);
            yield "</div>
                        </div>
                        <div class=\"da-user-pulse\"></div>
                    </div>
                ";
            ++$context['loop']['index0'];
            ++$context['loop']['index'];
            $context['loop']['first'] = false;
            if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                --$context['loop']['revindex0'];
                --$context['loop']['revindex'];
                $context['loop']['last'] = 0 === $context['loop']['revindex0'];
            }
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['u'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 185
        yield "            </div>
        </div>

        <!-- Top Users Today -->
        <div class=\"da-section\">
            <div class=\"da-section-head\">
                <h3 class=\"da-section-title\">
                    <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#f59e0b\" stroke-width=\"2\"><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"/></svg>
                    Top utenti oggi
                </h3>
            </div>
            <div class=\"da-section-body\" id=\"top-users\">
                ";
        // line 197
        if ((($tmp =  !($context["topUsers"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 198
            yield "                    <div class=\"da-empty\">Nessun dato disponibile</div>
                ";
        }
        // line 200
        yield "                ";
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["topUsers"] ?? null));
        $context['loop'] = [
          'parent' => $context['_parent'],
          'index0' => 0,
          'index'  => 1,
          'first'  => true,
        ];
        if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
            $length = count($context['_seq']);
            $context['loop']['revindex0'] = $length - 1;
            $context['loop']['revindex'] = $length;
            $context['loop']['length'] = $length;
            $context['loop']['last'] = 1 === $length;
        }
        foreach ($context['_seq'] as $context["_key"] => $context["u"]) {
            // line 201
            yield "                    ";
            $context["rankClass"] = (((CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, false, 201) == 0)) ? ("da-rank-1") : ((((CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, false, 201) == 1)) ? ("da-rank-2") : ((((CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, false, 201) == 2)) ? ("da-rank-3") : ("da-rank-n"))))));
            // line 202
            yield "                    ";
            $context["secs"] = $this->extensions['Twig\Extension\CoreExtension']->formatNumber(((CoreExtension::getAttribute($this->env, $this->source, $context["u"], "total_seconds", [], "any", true, true, false, 202)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["u"], "total_seconds", [], "any", false, false, false, 202), 0)) : (0)), 0, ".", "");
            // line 203
            yield "                    ";
            $context["hrs"] = Twig\Extension\CoreExtension::round((($context["secs"] ?? null) / 3600), 0, "floor");
            // line 204
            yield "                    ";
            $context["mins"] = Twig\Extension\CoreExtension::round(((($context["secs"] ?? null) % 3600) / 60), 0, "floor");
            // line 205
            yield "                    <div class=\"da-top-row\">
                        <div class=\"da-top-rank ";
            // line 206
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["rankClass"] ?? null), "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 206), "html", null, true);
            yield "</div>
                        <div class=\"da-top-name\">";
            // line 207
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["u"], "first_name", [], "any", false, false, false, 207), "html", null, true);
            yield "</div>
                        <div class=\"da-top-time\">";
            // line 208
            if ((($context["hrs"] ?? null) > 0)) {
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["hrs"] ?? null), "html", null, true);
                yield "h ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["mins"] ?? null), "html", null, true);
                yield "m";
            } else {
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["mins"] ?? null), "html", null, true);
                yield "m";
            }
            yield "</div>
                    </div>
                ";
            ++$context['loop']['index0'];
            ++$context['loop']['index'];
            $context['loop']['first'] = false;
            if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                --$context['loop']['revindex0'];
                --$context['loop']['revindex'];
                $context['loop']['last'] = 0 === $context['loop']['revindex0'];
            }
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['u'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 211
        yield "            </div>
        </div>

        <!-- Recent Activity -->
        <div class=\"da-section\">
            <div class=\"da-section-head\">
                <h3 class=\"da-section-title\">
                    <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"#8b5cf6\" stroke-width=\"2\"><polyline points=\"22 12 18 12 15 21 9 3 6 12 2 12\"/></svg>
                    Attività recenti
                </h3>
            </div>
            <div class=\"da-section-body\" id=\"recent-actions\" style=\"max-height:320px;overflow-y:auto;\">
                ";
        // line 223
        if ((($tmp =  !($context["recentActions"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 224
            yield "                    <div class=\"da-empty\">Nessuna attività recente</div>
                ";
        }
        // line 226
        yield "                ";
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["recentActions"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["r"]) {
            // line 227
            yield "                    <div class=\"da-act-row\">
                        <div class=\"da-act-dot\"></div>
                        <div style=\"min-width:0;\">
                            <div>
                                <span class=\"da-act-name\">";
            // line 231
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["r"], "first_name", [], "any", false, false, false, 231), "html", null, true);
            yield "</span>
                                <span class=\"da-act-action\">";
            // line 232
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFilter('friendly_action')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["r"], "action", [], "any", false, false, false, 232)), "html", null, true);
            yield "</span>
                            </div>
                            <span class=\"da-act-page\">";
            // line 234
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFilter('friendly_page')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["r"], "page", [], "any", false, false, false, 234)), "html", null, true);
            yield "</span>
                        </div>
                    </div>
                ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['r'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 238
        yield "            </div>
        </div>

    </div>

</div>

<!-- ================= AUTO REFRESH ================= -->
<script src=\"/assets/js/views/dashboard/partials/dashboard_admin.js\"></script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "dashboard/partials/admin.html.twig";
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
        return array (  538 => 238,  528 => 234,  523 => 232,  519 => 231,  513 => 227,  508 => 226,  504 => 224,  502 => 223,  488 => 211,  463 => 208,  459 => 207,  453 => 206,  450 => 205,  447 => 204,  444 => 203,  441 => 202,  438 => 201,  420 => 200,  416 => 198,  414 => 197,  400 => 185,  381 => 180,  377 => 179,  370 => 177,  367 => 176,  364 => 175,  361 => 174,  343 => 173,  340 => 172,  336 => 170,  334 => 169,  328 => 166,  311 => 151,  303 => 148,  296 => 146,  290 => 143,  284 => 139,  282 => 138,  275 => 136,  268 => 134,  262 => 131,  251 => 125,  244 => 123,  238 => 120,  227 => 114,  205 => 97,  196 => 90,  189 => 89,  182 => 85,  175 => 81,  169 => 78,  162 => 74,  156 => 71,  134 => 52,  127 => 50,  121 => 47,  117 => 46,  108 => 42,  103 => 40,  92 => 32,  87 => 30,  76 => 22,  71 => 20,  57 => 9,  51 => 8,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "dashboard/partials/admin.html.twig", "/var/www/bob.csmontaggi.it/public/templates/dashboard/partials/admin.html.twig");
    }
}
