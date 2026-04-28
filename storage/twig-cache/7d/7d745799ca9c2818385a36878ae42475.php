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

/* dashboard/index.html.twig */
class __TwigTemplate_6b2b8e4874d3302e73a8e66cfe68c6a6 extends Template
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
            'content' => [$this, 'block_content'],
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

    // line 2
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_content(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 3
        yield "<div class=\"intro-y mt-8\">
    ";
        // line 4
        if ((($context["role"] ?? null) == "admin")) {
            // line 5
            yield "        ";
            yield from $this->load("dashboard/partials/admin.html.twig", 5)->unwrap()->yield($context);
            // line 6
            yield "    ";
        } elseif ((($context["role"] ?? null) == "document_manager")) {
            // line 7
            yield "        ";
            yield from $this->load("dashboard/partials/documents.html.twig", 7)->unwrap()->yield($context);
            // line 8
            yield "    ";
        } elseif ((($context["role"] ?? null) == "offerte")) {
            // line 9
            yield "        ";
            yield from $this->load("dashboard/partials/offers.html.twig", 9)->unwrap()->yield($context);
            // line 10
            yield "    ";
        } elseif (((($context["role"] ?? null) == "cantiere") || (($context["role"] ?? null) == "manager"))) {
            // line 11
            yield "        ";
            yield from $this->load("dashboard/partials/cantieri.html.twig", 11)->unwrap()->yield($context);
            // line 12
            yield "    ";
        } else {
            // line 13
            yield "        ";
            yield from $this->load("dashboard/partials/user.html.twig", 13)->unwrap()->yield($context);
            // line 14
            yield "    ";
        }
        // line 15
        yield "</div>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "dashboard/index.html.twig";
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
        return array (  93 => 15,  90 => 14,  87 => 13,  84 => 12,  81 => 11,  78 => 10,  75 => 9,  72 => 8,  69 => 7,  66 => 6,  63 => 5,  61 => 4,  58 => 3,  51 => 2,  40 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "dashboard/index.html.twig", "/var/www/bob.csmontaggi.it/public/templates/dashboard/index.html.twig");
    }
}
