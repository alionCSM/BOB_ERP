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

/* layout/_flash.html.twig */
class __TwigTemplate_70883ab2e6bb8f1d19b31acbe437e23c extends Template
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
        if ((($tmp = ($context["flash"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 2
            yield "<div id=\"toast-";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["flash"] ?? null), "type", [], "any", false, false, false, 2), "html", null, true);
            yield "\"
     style=\"position:fixed;top:30px;left:50%;transform:translateX(-50%) scale(0.95);
            background-color:";
            // line 4
            if ((CoreExtension::getAttribute($this->env, $this->source, ($context["flash"] ?? null), "type", [], "any", false, false, false, 4) == "success")) {
                yield "#16a34a";
            } elseif ((CoreExtension::getAttribute($this->env, $this->source, ($context["flash"] ?? null), "type", [], "any", false, false, false, 4) == "error")) {
                yield "#dc2626";
            } else {
                yield "#2563eb";
            }
            yield " !important;
            color:white !important;padding:12px 24px;border-radius:9999px;font-weight:600;
            box-shadow:0 10px 20px rgba(0,0,0,0.15);z-index:9999;opacity:0;
            transition:all 0.3s ease-out;text-align:center;font-size:14px;\">
    ";
            // line 8
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["flash"] ?? null), "message", [], "any", false, false, false, 8), "html", null, true);
            yield "
</div>
<script src=\"/assets/js/includes/flash.js\"></script>
";
        }
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "layout/_flash.html.twig";
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
        return array (  63 => 8,  50 => 4,  44 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "layout/_flash.html.twig", "/var/www/bob.csmontaggi.it/public/templates/layout/_flash.html.twig");
    }
}
