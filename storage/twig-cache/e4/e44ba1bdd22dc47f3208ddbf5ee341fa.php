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

/* auth/login.html.twig */
class __TwigTemplate_cc3b1c3f88d5ea53654c05939f45569c extends Template
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
        if ((($tmp = ($context["autoRedirect"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 2
            yield "<!DOCTYPE html>
<html lang=\"it\">
<head>
    <meta charset=\"utf-8\">
    <title>Accesso automatico - BOB</title>
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
    <meta http-equiv=\"refresh\" content=\"1;url=/\" />
    <link rel=\"stylesheet\" href=\"";
            // line 9
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/css/app.css\" />
    <link rel=\"stylesheet\" href=\"/assets/css/views/auth/login.css\">
</head>
<body class=\"flex items-center justify-center min-h-screen\">
<div class=\"text-center text-white\">
    <div class=\"flex justify-center mb-6\">
        <svg class=\"animate-spin h-10 w-10 text-primary\" xmlns=\"http://www.w3.org/2000/svg\" fill=\"none\" viewBox=\"0 0 24 24\">
            <circle class=\"opacity-25\" cx=\"12\" cy=\"12\" r=\"10\" stroke=\"currentColor\" stroke-width=\"4\"></circle>
            <path class=\"opacity-75\" fill=\"currentColor\" d=\"M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z\"></path>
        </svg>
    </div>
    <h1 class=\"text-2xl font-semibold mb-2\">Accesso automatico…</h1>
    <p class=\"text-slate-300 text-sm\">Verifica del dispositivo in corso</p>
</div>
</body>
</html>
";
        } else {
            // line 26
            yield "<!DOCTYPE html>
<html lang=\"en\" class=\"light\">
<head>
    <meta charset=\"utf-8\">
    <link href=\"";
            // line 30
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/images/logo.png\" rel=\"shortcut icon\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
    <meta name=\"description\" content=\"BOB\">
    <title>Login - BOB</title>
    <link rel=\"stylesheet\" href=\"";
            // line 34
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/css/app.css\" />
</head>
<body class=\"login\">
<div class=\"container sm:px-10\">
    <div class=\"block xl:grid grid-cols-2 gap-4\">
        <!-- BEGIN: Login Info -->
        <div class=\"hidden xl:flex flex-col min-h-screen\">
            <a href=\"\" class=\"-intro-x flex items-center pt-5\">
                <img alt=\"Bob logo\" class=\"w-8\" src=\"";
            // line 42
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/images/logo.png\">
                <span class=\"text-white text-xl ml-3\"> BOB</span>
            </a>
            <div class=\"my-auto\">
                <img alt=\"\" class=\"-intro-x w-2/3 -mt-36\" src=\"";
            // line 46
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/images/csmontaggi_logo.png\">
            </div>
        </div>
        <!-- END: Login Info -->
        <!-- BEGIN: Login Form -->
        <div class=\"h-screen xl:h-auto flex py-5 xl:py-0 my-10 xl:my-0\">
            <div class=\"my-auto mx-auto xl:ml-20 bg-white dark:bg-darkmode-600 xl:bg-transparent px-5 sm:px-8 py-8 xl:p-0 rounded-md shadow-md xl:shadow-none w-full sm:w-3/4 lg:w-2/4 xl:w-auto\">
                <h2 class=\"intro-x font-bold text-2xl xl:text-3xl text-center xl:text-left\">Accedi</h2>

                ";
            // line 55
            if ((($tmp = ($context["error"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 56
                yield "                    <div class=\"intro-x mt-4\">
                        <div class=\"alert alert-danger flex items-center\">
                            <i data-lucide=\"alert-circle\" class=\"w-5 h-5 mr-2\"></i>
                            ";
                // line 59
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["error"] ?? null), "html", null, true);
                yield "
                        </div>
                    </div>
                ";
            }
            // line 63
            yield "
                <form method=\"POST\" action=\"/login\">
                <div class=\"intro-x mt-8\">
                    <input type=\"text\"
                           name=\"username\"
                           value=\"";
            // line 68
            yield (((array_key_exists("postUsername", $context) &&  !(null === $context["postUsername"]))) ? ($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["postUsername"], "html", null, true)) : (""));
            yield "\"
                           class=\"intro-x login__input form-control py-3 px-4 block\"
                           placeholder=\"Nome utente\">
                    <input type=\"password\" name=\"password\" class=\"intro-x login__input form-control py-3 px-4 block mt-4\" placeholder=\"Password\">
                    <div class=\"intro-x flex items-center mt-4\">
                        <input type=\"checkbox\" name=\"remember_me\" id=\"remember_me\" class=\"mr-2 rounded border-gray-300\">
                        <label for=\"remember_me\" class=\"text-sm text-slate-600\">Ricordami su questo dispositivo</label>
                    </div>
                </div>
                <div class=\"intro-x mt-5 xl:mt-8 text-center xl:text-left\">
                    <button type=\"submit\" class=\"btn btn-primary py-3 px-4 w-full xl:w-32 xl:mr-3 align-top\">Login</button>
                </div>
                </form>
            </div>
        </div>
        <!-- END: Login Form -->
    </div>
</div>
<script src=\"";
            // line 86
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["appUrl"] ?? null), "html", null, true);
            yield "/includes/template/dist/js/app.js\"></script>
</body>
</html>
";
        }
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "auth/login.html.twig";
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
        return array (  158 => 86,  137 => 68,  130 => 63,  123 => 59,  118 => 56,  116 => 55,  104 => 46,  97 => 42,  86 => 34,  79 => 30,  73 => 26,  53 => 9,  44 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "auth/login.html.twig", "/var/www/bob.csmontaggi.it/public/templates/auth/login.html.twig");
    }
}
