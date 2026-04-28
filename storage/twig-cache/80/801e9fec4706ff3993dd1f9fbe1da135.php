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

/* offers/show.html.twig */
class __TwigTemplate_7d81efc7f23ccc834159e864e9f80a86 extends Template
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

        $this->blocks = [
            'head' => [$this, 'block_head'],
            'content' => [$this, 'block_content'],
            'scripts' => [$this, 'block_scripts'],
        ];
    }

    protected function doGetParent(array $context): bool|string|Template|TemplateWrapper
    {
        // line 1
        return "layout/base.html.twig";
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        $this->parent = $this->load("layout/base.html.twig", 1);
        yield from $this->parent->unwrap()->yield($context, array_merge($this->blocks, $blocks));
    }

    // line 3
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_head(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 4
        yield "<link rel=\"stylesheet\" href=\"/assets/css/views/offers/offers_list.css\">
<link rel=\"stylesheet\" href=\"/assets/css/views/offers/offers_form.css\">
<link rel=\"stylesheet\" href=\"/assets/css/views/offers/offer_show.css\">
";
        yield from [];
    }

    // line 9
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_content(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 10
        $context["s"] = (((CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "status", [], "any", true, true, false, 10) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "status", [], "any", false, false, false, 10)))) ? (CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "status", [], "any", false, false, false, 10)) : ("bozza"));
        // line 11
        $context["statusLabels"] = ["bozza" => "Bozza", "inviata" => "Inviata", "in_trattativa" => "In Trattativa", "approvata" => "Approvata", "rifiutata" => "Rifiutata", "scaduta" => "Scaduta"];
        // line 12
        yield "
<div class=\"cos-page\">

";
        // line 16
        yield "<div class=\"cos-hero\">
    <div class=\"cos-hero-left\">
        <div class=\"cos-hero-icon\">
            <svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\">
                <path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/>
                <polyline points=\"14 2 14 8 20 8\"/>
            </svg>
        </div>
        <div class=\"cos-hero-info\">
            <div class=\"cos-hero-row\">
                <h1 class=\"cos-hero-title\">Offerta ";
        // line 26
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "offer_number", [], "any", false, false, false, 26), "html", null, true);
        yield "</h1>
                <span class=\"cfl-badge cfl-badge-";
        // line 27
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["s"] ?? null), "html", null, true);
        yield "\" id=\"heroBadge\">";
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["statusLabels"] ?? null), ($context["s"] ?? null), [], "array", true, true, false, 27) &&  !(null === (($_v0 = ($context["statusLabels"] ?? null)) && is_array($_v0) || $_v0 instanceof ArrayAccess ? ($_v0[($context["s"] ?? null)] ?? null) : null)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((($_v1 = ($context["statusLabels"] ?? null)) && is_array($_v1) || $_v1 instanceof ArrayAccess ? ($_v1[($context["s"] ?? null)] ?? null) : null), "html", null, true)) : ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["s"] ?? null), "html", null, true)));
        yield "</span>
            </div>
            <p class=\"cos-hero-sub\">
                <svg viewBox=\"0 0 24 24\"><path d=\"M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/></svg>
                ";
        // line 31
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", true, true, false, 31) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", false, false, false, 31)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", false, false, false, 31), "html", null, true)) : ("—"));
        yield "
                <span class=\"cos-hero-sep\">·</span>
                <svg viewBox=\"0 0 24 24\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\"/><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"/><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"/><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"/></svg>
                ";
        // line 34
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "offer_date", [], "any", false, false, false, 34), "d/m/Y"), "html", null, true);
        yield "
            </p>
        </div>
    </div>
    <div class=\"cos-hero-actions\">
        <a href=\"/offers\" class=\"cfo-btn-back\">
            <svg viewBox=\"0 0 24 24\"><path d=\"M19 12H5M12 19l-7-7 7-7\"/></svg>
            Lista
        </a>
        <a href=\"/offers/";
        // line 43
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "/revise\" class=\"cos-btn-action cos-btn-revise\">
            <svg viewBox=\"0 0 24 24\"><path d=\"M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7\"/><path d=\"M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z\"/></svg>
            Revisione
        </a>
        <a href=\"/offers/";
        // line 47
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "/pdf\" target=\"_blank\" class=\"cos-btn-action cos-btn-pdf\">
            <svg viewBox=\"0 0 24 24\"><path d=\"M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4\"/><polyline points=\"7 10 12 15 17 10\"/><line x1=\"12\" y1=\"15\" x2=\"12\" y2=\"3\"/></svg>
            Scarica PDF
        </a>
        <a href=\"/offers/";
        // line 51
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "/edit\" class=\"cos-btn-edit\">
            <svg viewBox=\"0 0 24 24\"><path d=\"M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7\"/><path d=\"M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z\"/></svg>
            Modifica
        </a>
    </div>
</div>

";
        // line 59
        yield "<div class=\"cos-grid\">

    ";
        // line 62
        yield "    <div class=\"cos-left\">

        ";
        // line 65
        yield "        <div class=\"cos-card\">
            <div class=\"cos-card-title\">
                <svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><polyline points=\"12 6 12 12 16 14\"/></svg>
                Stato Offerta
            </div>
            <div class=\"cfo-status-select-group\" id=\"statusSelectGroup\" data-offer-id=\"";
        // line 70
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "\">
                ";
        // line 71
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["statusLabels"] ?? null));
        foreach ($context['_seq'] as $context["val"] => $context["label"]) {
            // line 72
            yield "                <button type=\"button\"
                        class=\"cfl-badge cfl-badge-";
            // line 73
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["val"], "html", null, true);
            yield " cfo-status-btn ";
            if ((($context["s"] ?? null) == $context["val"])) {
                yield "active";
            }
            yield "\"
                        data-val=\"";
            // line 74
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["val"], "html", null, true);
            yield "\">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["label"], "html", null, true);
            yield "</button>
                ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['val'], $context['label'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 76
        yield "            </div>
        </div>

        ";
        // line 80
        yield "        <div class=\"cos-card\">
            <div class=\"cos-card-title\">
                <svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>
                Dettagli Offerta
            </div>
            <div class=\"cos-details\">
                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Cliente</span>
                    <span class=\"cos-detail-value\">";
        // line 88
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", true, true, false, 88) &&  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", false, false, false, 88)))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "client_name", [], "any", false, false, false, 88), "html", null, true)) : ("—"));
        yield "</span>
                </div>
                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Numero</span>
                    <span class=\"cos-detail-value\">";
        // line 92
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "offer_number", [], "any", false, false, false, 92), "html", null, true);
        yield "</span>
                </div>
                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Data</span>
                    <span class=\"cos-detail-value\">";
        // line 96
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "offer_date", [], "any", false, false, false, 96), "d/m/Y"), "html", null, true);
        yield "</span>
                </div>
                ";
        // line 98
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "subject", [], "any", false, false, false, 98)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 99
            yield "                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Oggetto</span>
                    <span class=\"cos-detail-value\">";
            // line 101
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "subject", [], "any", false, false, false, 101), "html", null, true);
            yield "</span>
                </div>
                ";
        }
        // line 104
        yield "                ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "reference", [], "any", false, false, false, 104)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 105
            yield "                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Riferimento</span>
                    <span class=\"cos-detail-value\">";
            // line 107
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "reference", [], "any", false, false, false, 107), "html", null, true);
            yield "</span>
                </div>
                ";
        }
        // line 110
        yield "                ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "cortese_att", [], "any", false, false, false, 110)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 111
            yield "                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Att.ne</span>
                    <span class=\"cos-detail-value\">";
            // line 113
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "cortese_att", [], "any", false, false, false, 113), "html", null, true);
            yield "</span>
                </div>
                ";
        }
        // line 116
        yield "                <div class=\"cos-detail-row cos-detail-total\">
                    <span class=\"cos-detail-label\">Totale</span>
                    <span class=\"cos-detail-value cos-total-value\">";
        // line 118
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "total_amount", [], "any", false, false, false, 118), "html", null, true);
        yield "</span>
                </div>
                ";
        // line 120
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "is_revision", [], "any", false, false, false, 120)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 121
            yield "                <div class=\"cos-detail-row\">
                    <span class=\"cos-detail-label\">Revisione di</span>
                    <span class=\"cos-detail-value\">";
            // line 123
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "base_offer_number", [], "any", false, false, false, 123), "html", null, true);
            yield "</span>
                </div>
                ";
        }
        // line 126
        yield "            </div>
        </div>

        ";
        // line 130
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "note_interne", [], "any", false, false, false, 130)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 131
            yield "        <div class=\"cos-card cos-card-internal\">
            <div class=\"cos-card-title\">
                <svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"12\" y1=\"16\" x2=\"12\" y2=\"12\"/><line x1=\"12\" y1=\"8\" x2=\"12.01\" y2=\"8\"/></svg>
                Note Interne
            </div>
            <p class=\"cos-internal-note\">";
            // line 136
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "note_interne", [], "any", false, false, false, 136), "html", null, true);
            yield "</p>
            ";
            // line 137
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["offerData"] ?? null), "doc_path", [], "any", false, false, false, 137)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 138
                yield "            <a href=\"/offers/";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
                yield "/doc\" target=\"_blank\" class=\"cos-doc-link\">
                <svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>
                Visualizza PDF allegato
            </a>
            ";
            }
            // line 143
            yield "        </div>
        ";
        }
        // line 145
        yield "
        ";
        // line 147
        yield "        <div class=\"cos-card\">
            <div class=\"cos-followup-header\">
                <div class=\"cos-card-title\" style=\"margin-bottom:0\">
                    <svg viewBox=\"0 0 24 24\"><path d=\"M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.68A2 2 0 012 .91h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z\"/></svg>
                    Follow-up
                </div>
                <button type=\"button\" class=\"cfo-btn-primary cfo-btn-sm\" id=\"toggleFollowupForm\">
                    <svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"12\" y1=\"8\" x2=\"12\" y2=\"16\"/><line x1=\"8\" y1=\"12\" x2=\"16\" y2=\"12\"/></svg>
                    Aggiungi
                </button>
            </div>

            ";
        // line 160
        yield "            <div class=\"cfo-followup-form hidden\" id=\"followupForm\" style=\"margin-top:14px\">
                <div class=\"cfo-followup-form-inner\">
                    <div class=\"cfo-followup-type-grid\">
                        ";
        // line 163
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(["chiamata" => "Chiamata", "email" => "Email", "sms" => "SMS", "riunione" => "Riunione", "nota" => "Nota"]);
        foreach ($context['_seq'] as $context["val"] => $context["label"]) {
            // line 164
            yield "                        <label class=\"cfo-type-option\">
                            <input type=\"radio\" name=\"fu_type\" value=\"";
            // line 165
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["val"], "html", null, true);
            yield "\" ";
            if (($context["val"] == "nota")) {
                yield "checked";
            }
            yield ">
                            <span>";
            // line 166
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["label"], "html", null, true);
            yield "</span>
                        </label>
                        ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['val'], $context['label'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 169
        yield "                    </div>
                    <div class=\"cfo-followup-form-row\">
                        <div class=\"cfo-field\">
                            <div class=\"cfo-field-wrapper\">
                                <label>Data</label>
                                <input type=\"date\" id=\"fu_date\" value=\"";
        // line 174
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate("now", "Y-m-d"), "html", null, true);
        yield "\">
                            </div>
                        </div>
                        <div class=\"cfo-field cfo-field-grow\">
                            <div class=\"cfo-field-wrapper\">
                                <label>Nota</label>
                                <textarea id=\"fu_note\" rows=\"2\" placeholder=\"Descrivi l'attività...\"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class=\"cfo-followup-form-actions\">
                        <button type=\"button\" class=\"cfo-btn-secondary\" id=\"cancelFollowup\">Annulla</button>
                        <button type=\"button\" class=\"cfo-btn-primary\" id=\"saveFollowup\" data-offer-id=\"";
        // line 186
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "\">Salva</button>
                    </div>
                </div>
            </div>

            ";
        // line 192
        yield "            <div class=\"cfo-followup-list\" id=\"followupList\" style=\"margin-top:14px\">
                ";
        // line 193
        if (Twig\Extension\CoreExtension::testEmpty(($context["followups"] ?? null))) {
            // line 194
            yield "                <div class=\"cfo-followup-empty\" id=\"followupEmpty\">
                    <svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"12\" y1=\"8\" x2=\"12\" y2=\"12\"/><line x1=\"12\" y1=\"16\" x2=\"12.01\" y2=\"16\"/></svg>
                    Nessuna attività registrata
                </div>
                ";
        } else {
            // line 199
            yield "                ";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["followups"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["fu"]) {
                // line 200
                yield "                <div class=\"cfo-followup-item\" data-fu-id=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "id", [], "any", false, false, false, 200), "html", null, true);
                yield "\">
                    <div class=\"cfo-followup-item-icon cfo-futype-";
                // line 201
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "type", [], "any", false, false, false, 201), "html", null, true);
                yield "\">
                        ";
                // line 202
                if ((CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "type", [], "any", false, false, false, 202) == "chiamata")) {
                    yield "<svg viewBox=\"0 0 24 24\"><path d=\"M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.68A2 2 0 012 .91h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z\"/></svg>
                        ";
                } elseif ((CoreExtension::getAttribute($this->env, $this->source,                 // line 203
$context["fu"], "type", [], "any", false, false, false, 203) == "email")) {
                    yield "<svg viewBox=\"0 0 24 24\"><path d=\"M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z\"/><polyline points=\"22,6 12,13 2,6\"/></svg>
                        ";
                } elseif ((CoreExtension::getAttribute($this->env, $this->source,                 // line 204
$context["fu"], "type", [], "any", false, false, false, 204) == "sms")) {
                    yield "<svg viewBox=\"0 0 24 24\"><path d=\"M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z\"/></svg>
                        ";
                } elseif ((CoreExtension::getAttribute($this->env, $this->source,                 // line 205
$context["fu"], "type", [], "any", false, false, false, 205) == "riunione")) {
                    yield "<svg viewBox=\"0 0 24 24\"><path d=\"M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75\"/></svg>
                        ";
                } else {
                    // line 206
                    yield "<svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>";
                }
                // line 207
                yield "                    </div>
                    <div class=\"cfo-followup-item-body\">
                        <div class=\"cfo-followup-item-meta\">
                            <span class=\"cfo-followup-item-type\">";
                // line 210
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "type", [], "any", false, false, false, 210)), "html", null, true);
                yield "</span>
                            <span class=\"cfo-followup-item-date\">";
                // line 211
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "followup_date", [], "any", false, false, false, 211), "d/m/Y"), "html", null, true);
                yield "</span>
                            <span class=\"cfo-followup-item-author\">";
                // line 212
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "creator_name", [], "any", false, false, false, 212), "html", null, true);
                yield "</span>
                        </div>
                        <p class=\"cfo-followup-item-note\">";
                // line 214
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "note", [], "any", false, false, false, 214), "html", null, true);
                yield "</p>
                    </div>
                    <button type=\"button\" class=\"cfo-followup-delete\" data-fu-id=\"";
                // line 216
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["fu"], "id", [], "any", false, false, false, 216), "html", null, true);
                yield "\" data-offer-id=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
                yield "\" title=\"Elimina\">
                        <svg viewBox=\"0 0 24 24\"><polyline points=\"3 6 5 6 21 6\"/><path d=\"M19 6l-1 14H6L5 6\"/><path d=\"M10 11v6M14 11v6\"/><path d=\"M9 6V4h6v2\"/></svg>
                    </button>
                </div>
                ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['fu'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 221
            yield "                ";
        }
        // line 222
        yield "            </div>
        </div>

    </div>";
        // line 226
        yield "
    ";
        // line 228
        yield "    <div class=\"cos-right\">
        <div class=\"cos-pdf-wrapper\">
            <div class=\"cos-pdf-toolbar\">
                <span class=\"cos-pdf-label\">
                    <svg viewBox=\"0 0 24 24\"><path d=\"M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>
                    Anteprima PDF
                </span>
                <a href=\"/offers/";
        // line 235
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "/pdf\" target=\"_blank\" class=\"cos-pdf-open\">
                    <svg viewBox=\"0 0 24 24\"><path d=\"M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6\"/><polyline points=\"15 3 21 3 21 9\"/><line x1=\"10\" y1=\"14\" x2=\"21\" y2=\"3\"/></svg>
                    Apri in nuova scheda
                </a>
            </div>
            <iframe class=\"cos-pdf-iframe\" src=\"/offers/";
        // line 240
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["offerId"] ?? null), "html", null, true);
        yield "/pdf\" title=\"Anteprima PDF\"></iframe>
        </div>
    </div>

</div>";
        // line 245
        yield "
</div>";
        yield from [];
    }

    // line 249
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_scripts(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 250
        yield "<script src=\"/assets/js/views/offers/offer_show.js\"></script>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "offers/show.html.twig";
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
        return array (  528 => 250,  521 => 249,  515 => 245,  508 => 240,  500 => 235,  491 => 228,  488 => 226,  483 => 222,  480 => 221,  467 => 216,  462 => 214,  457 => 212,  453 => 211,  449 => 210,  444 => 207,  441 => 206,  436 => 205,  432 => 204,  428 => 203,  424 => 202,  420 => 201,  415 => 200,  410 => 199,  403 => 194,  401 => 193,  398 => 192,  390 => 186,  375 => 174,  368 => 169,  359 => 166,  351 => 165,  348 => 164,  344 => 163,  339 => 160,  325 => 147,  322 => 145,  318 => 143,  309 => 138,  307 => 137,  303 => 136,  296 => 131,  293 => 130,  288 => 126,  282 => 123,  278 => 121,  276 => 120,  271 => 118,  267 => 116,  261 => 113,  257 => 111,  254 => 110,  248 => 107,  244 => 105,  241 => 104,  235 => 101,  231 => 99,  229 => 98,  224 => 96,  217 => 92,  210 => 88,  200 => 80,  195 => 76,  185 => 74,  177 => 73,  174 => 72,  170 => 71,  166 => 70,  159 => 65,  155 => 62,  151 => 59,  141 => 51,  134 => 47,  127 => 43,  115 => 34,  109 => 31,  100 => 27,  96 => 26,  84 => 16,  79 => 12,  77 => 11,  75 => 10,  68 => 9,  60 => 4,  53 => 3,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "offers/show.html.twig", "/var/www/bob.csmontaggi.it/public/templates/offers/show.html.twig");
    }
}
