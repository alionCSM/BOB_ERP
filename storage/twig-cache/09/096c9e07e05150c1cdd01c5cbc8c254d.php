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

/* layout/base.html.twig */
class __TwigTemplate_698aa1bdaf4207529ce8a85182ec4521 extends Template
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
            'head' => [$this, 'block_head'],
            'content' => [$this, 'block_content'],
            'scripts' => [$this, 'block_scripts'],
        ];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        yield "<!DOCTYPE html>
<html lang=\"en\" class=\"light\">
<head>
    <meta charset=\"utf-8\">
    <link href=\"";
        // line 5
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFunction('asset')->getCallable()("/includes/template/dist/images/logo.png"), "html", null, true);
        yield "\" rel=\"shortcut icon\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
    <meta name=\"csrf-token\" content=\"";
        // line 7
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["csrfToken"] ?? null), "html", null, true);
        yield "\">
    <title>BOB - ";
        // line 8
        yield (((array_key_exists("pageTitle", $context) &&  !(null === $context["pageTitle"]))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["pageTitle"], "html", null, true)) : (""));
        yield "</title>
    <link rel=\"stylesheet\" href=\"";
        // line 9
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFunction('asset')->getCallable()("/includes/template/dist/css/app.css"), "html", null, true);
        yield "\">
    <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css\">
    ";
        // line 11
        yield from $this->unwrap()->yieldBlock('head', $context, $blocks);
        // line 12
        yield "</head>
<body class=\"py-5\">

";
        // line 15
        yield from $this->load("layout/_flash.html.twig", 15)->unwrap()->yield($context);
        // line 16
        yield "
<!-- BEGIN: Mobile Menu -->
";
        // line 18
        yield from $this->load("layout/_mobile_menu.html.twig", 18)->unwrap()->yield($context);
        // line 19
        yield "<!-- END: Mobile Menu -->

<div class=\"flex mt-[4.7rem] md:mt-0\">
    <!-- BEGIN: Side Menu -->
    ";
        // line 23
        yield from $this->load("layout/_menu.html.twig", 23)->unwrap()->yield($context);
        // line 24
        yield "    <!-- END: Side Menu -->

    <!-- BEGIN: Content -->
    <div class=\"content\">
        <!-- BEGIN: Top Bar -->
        ";
        // line 29
        yield from $this->load("layout/_topbar.html.twig", 29)->unwrap()->yield($context);
        // line 30
        yield "        <!-- END: Top Bar -->

        ";
        // line 32
        yield from $this->unwrap()->yieldBlock('content', $context, $blocks);
        // line 33
        yield "    </div>
    <!-- END: Content -->
</div>

<link rel=\"stylesheet\" href=\"/assets/css/includes/template/footer.css\">
<script src=\"/assets/js/includes/template/footer.js\"></script>
<script src=\"";
        // line 39
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFunction('asset')->getCallable()("/includes/template/dist/js/app.js"), "html", null, true);
        yield "\"></script>
";
        // line 40
        yield from $this->unwrap()->yieldBlock('scripts', $context, $blocks);
        // line 41
        yield "</body>
</html>
";
        yield from [];
    }

    // line 11
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_head(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        yield from [];
    }

    // line 32
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_content(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        yield from [];
    }

    // line 40
    /**
     * @return iterable<null|scalar|\Stringable>
     */
    public function block_scripts(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "layout/base.html.twig";
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
        return array (  148 => 40,  138 => 32,  128 => 11,  121 => 41,  119 => 40,  115 => 39,  107 => 33,  105 => 32,  101 => 30,  99 => 29,  92 => 24,  90 => 23,  84 => 19,  82 => 18,  78 => 16,  76 => 15,  71 => 12,  69 => 11,  64 => 9,  60 => 8,  56 => 7,  51 => 5,  45 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "layout/base.html.twig", "/var/www/bob.csmontaggi.it/public/templates/layout/base.html.twig");
    }
}
