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

/* layout/_menu.html.twig */
class __TwigTemplate_e9ac38491e8adafc5a6a01e8c9a5126a extends Template
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
        // line 2
        yield "
<nav class=\"side-nav\">

    <a href=\"/dashboard\" class=\"intro-x flex items-center pl-5 pt-4\">
        <img alt=\"Bob Logo\" class=\"w-10\" src=\"";
        // line 6
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getFunction('asset')->getCallable()("/includes/template/dist/images/logo.png"), "html", null, true);
        yield "\">
        <span class=\"hidden xl:block text-white text-lg ml-3\"> BOB </span>
    </a>

    <div class=\"side-nav__devider my-6\"></div>

    <ul>

        ";
        // line 14
        if ((($tmp = ($context["isCompanyScopedUser"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 15
            yield "
            ";
            // line 17
            yield "            ";
            $context["companiesActive"] = (is_string($_v0 = ($context["currentPath"] ?? null)) && is_string($_v1 = "/companies") && str_starts_with($_v0, $_v1));
            // line 18
            yield "            <li>
                <a href=\"/companies/my\" class=\"side-menu ";
            // line 19
            yield (((($tmp = ($context["companiesActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"building\"></i></div>
                    <div class=\"side-menu__title\">Le Mie Aziende</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>

            ";
            // line 27
            yield "            ";
            $context["usersActive"] = (is_string($_v2 = ($context["currentPath"] ?? null)) && is_string($_v3 = "/users") && str_starts_with($_v2, $_v3));
            // line 28
            yield "            <li>
                <a href=\"/users/workers\" class=\"side-menu ";
            // line 29
            yield (((($context["usersActive"] ?? null) && (($context["currentPath"] ?? null) != "/users/create"))) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"users\"></i></div>
                    <div class=\"side-menu__title\">Operai</div>
                </a>
            </li>
            <li>
                <a href=\"/users/create\" class=\"side-menu ";
            // line 35
            yield (((($context["currentPath"] ?? null) == "/users/create")) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"user-plus\"></i></div>
                    <div class=\"side-menu__title\">Nuovo Operaio</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>

            ";
            // line 43
            yield "            <li>
                <a href=\"/documents/expired-cv\" class=\"side-menu ";
            // line 44
            yield (((($context["currentPath"] ?? null) == "/documents/expired-cv")) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"alert-triangle\"></i></div>
                    <div class=\"side-menu__title\">Scadenze</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>

        ";
        } else {
            // line 52
            yield "
            ";
            // line 54
            yield "            ";
            $context["homeActive"] = ((($context["currentPath"] ?? null) == "/") || (($context["currentPath"] ?? null) == "/dashboard"));
            // line 55
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 56
            yield (((($tmp = ($context["homeActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"home\"></i></div>
                    <div class=\"side-menu__title\">
                        Home
                        <div class=\"side-menu__sub-icon ";
            // line 60
            yield (((($tmp = ($context["homeActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\">
                            <i data-lucide=\"chevron-down\"></i>
                        </div>
                    </div>
                </a>
                <ul class=\"";
            // line 65
            yield (((($tmp = ($context["homeActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li>
                        <a href=\"/dashboard\" class=\"side-menu ";
            // line 67
            yield (((($tmp = ($context["homeActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                            <div class=\"side-menu__icon\"><i data-lucide=\"monitor\"></i></div>
                            <div class=\"side-menu__title\">Dashboard</div>
                        </a>
                    </li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>

        ";
        }
        // line 77
        yield "
        ";
        // line 79
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["offers"], "method", false, false, false, 79)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 80
            yield "            ";
            $context["offersActive"] = (is_string($_v4 = ($context["currentPath"] ?? null)) && is_string($_v5 = "/offers") && str_starts_with($_v4, $_v5));
            // line 81
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 82
            yield (((($tmp = ($context["offersActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"file-check\"></i></div>
                    <div class=\"side-menu__title\">Offerte
                        <div class=\"side-menu__sub-icon ";
            // line 85
            yield (((($tmp = ($context["offersActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 88
            yield (((($tmp = ($context["offersActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/offers/create\" class=\"side-menu ";
            // line 89
            yield (((($context["currentPath"] ?? null) == "/offers/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus\"></i></div><div class=\"side-menu__title\">Crea Offerta</div></a></li>
                    <li><a href=\"/offers\" class=\"side-menu ";
            // line 90
            yield (((($context["currentPath"] ?? null) == "/offers")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"list\"></i></div><div class=\"side-menu__title\">Lista Offerte</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 95
        yield "
        ";
        // line 97
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["ordini"], "method", false, false, false, 97)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 98
            yield "            ";
            $context["ordiniActive"] = (is_string($_v6 = ($context["currentPath"] ?? null)) && is_string($_v7 = "/ordini") && str_starts_with($_v6, $_v7));
            // line 99
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 100
            yield (((($tmp = ($context["ordiniActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"clipboard-list\"></i></div>
                    <div class=\"side-menu__title\">Ordini Consorziata
                        <div class=\"side-menu__sub-icon ";
            // line 103
            yield (((($tmp = ($context["ordiniActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 106
            yield (((($tmp = ($context["ordiniActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/ordini/create\" class=\"side-menu ";
            // line 107
            yield (((($context["currentPath"] ?? null) == "/ordini/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus\"></i></div><div class=\"side-menu__title\">Nuovo Ordine</div></a></li>
                    <li><a href=\"/ordini\" class=\"side-menu ";
            // line 108
            yield (((($context["currentPath"] ?? null) == "/ordini")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"list\"></i></div><div class=\"side-menu__title\">Lista Ordini</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 113
        yield "
        ";
        // line 115
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["clients"], "method", false, false, false, 115)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 116
            yield "            ";
            $context["clientsActive"] = (is_string($_v8 = ($context["currentPath"] ?? null)) && is_string($_v9 = "/clients") && str_starts_with($_v8, $_v9));
            // line 117
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 118
            yield (((($tmp = ($context["clientsActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"users\"></i></div>
                    <div class=\"side-menu__title\">Clienti
                        <div class=\"side-menu__sub-icon ";
            // line 121
            yield (((($tmp = ($context["clientsActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 124
            yield (((($tmp = ($context["clientsActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/clients/create\" class=\"side-menu ";
            // line 125
            yield (((($context["currentPath"] ?? null) == "/clients/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"user-plus\"></i></div><div class=\"side-menu__title\">Nuovo Cliente</div></a></li>
                    <li><a href=\"/clients\" class=\"side-menu ";
            // line 126
            yield (((($context["currentPath"] ?? null) == "/clients")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"contact\"></i></div><div class=\"side-menu__title\">Lista Clienti</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 131
        yield "
        ";
        // line 133
        yield "        ";
        $context["worksitesActive"] = (is_string($_v10 = ($context["currentPath"] ?? null)) && is_string($_v11 = "/worksites") && str_starts_with($_v10, $_v11));
        // line 134
        yield "        ";
        if ((CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "type", [], "any", false, false, false, 134) == "worker")) {
            // line 135
            yield "            <li>
                <a href=\"/worksites/my\" class=\"side-menu ";
            // line 136
            yield (((($tmp = ($context["worksitesActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"hard-hat\"></i></div>
                    <div class=\"side-menu__title\">I miei cantieri</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        } elseif ((($tmp = CoreExtension::getAttribute($this->env, $this->source,         // line 142
($context["user"] ?? null), "canAccess", ["worksites"], "method", false, false, false, 142)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 143
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 144
            yield (((($tmp = ($context["worksitesActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"file-check\"></i></div>
                    <div class=\"side-menu__title\">Cantieri
                        <div class=\"side-menu__sub-icon ";
            // line 147
            yield (((($tmp = ($context["worksitesActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 150
            yield (((($tmp = ($context["worksitesActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/worksites/create\" class=\"side-menu ";
            // line 151
            yield (((($context["currentPath"] ?? null) == "/worksites/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus\"></i></div><div class=\"side-menu__title\">Crea Cantiere</div></a></li>
                    <li><a href=\"/worksites\" class=\"side-menu ";
            // line 152
            yield (((($context["currentPath"] ?? null) == "/worksites")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"list\"></i></div><div class=\"side-menu__title\">Lista Cantieri</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 157
        yield "
        ";
        // line 159
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["billing"], "method", false, false, false, 159)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 160
            yield "            ";
            $context["billingActive"] = ((is_string($_v12 = ($context["currentPath"] ?? null)) && is_string($_v13 = "/billing") && str_starts_with($_v12, $_v13)) || (is_string($_v14 = ($context["currentPath"] ?? null)) && is_string($_v15 = "/fatturazione/consorziate") && str_starts_with($_v14, $_v15)));
            // line 161
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 162
            yield (((($tmp = ($context["billingActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"euro\"></i></div>
                    <div class=\"side-menu__title\">Fatturazione
                        <div class=\"side-menu__sub-icon ";
            // line 165
            yield (((($tmp = ($context["billingActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 168
            yield (((($tmp = ($context["billingActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/billing\" class=\"side-menu ";
            // line 169
            yield (((($context["currentPath"] ?? null) == "/billing")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"activity\"></i></div><div class=\"side-menu__title\">Cantieri Movimentati</div></a></li>
                    <li><a href=\"/billing/clients\" class=\"side-menu ";
            // line 170
            yield (((is_string($_v16 = ($context["currentPath"] ?? null)) && is_string($_v17 = "/billing/client") && str_starts_with($_v16, $_v17))) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"banknote\"></i></div><div class=\"side-menu__title\">Fatturazione Clienti</div></a></li>
                    <li><a href=\"/fatturazione/consorziate\" class=\"side-menu ";
            // line 171
            yield (((is_string($_v18 = ($context["currentPath"] ?? null)) && is_string($_v19 = "/fatturazione/consorziate") && str_starts_with($_v18, $_v19))) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"credit-card\"></i></div><div class=\"side-menu__title\">Pagamenti Consorziate</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 176
        yield "
        ";
        // line 178
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["attendance"], "method", false, false, false, 178)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 179
            yield "            ";
            $context["attendanceActive"] = (is_string($_v20 = ($context["currentPath"] ?? null)) && is_string($_v21 = "/attendance") && str_starts_with($_v20, $_v21));
            // line 180
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 181
            yield (((($tmp = ($context["attendanceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"calendar\"></i></div>
                    <div class=\"side-menu__title\">Presenze
                        <div class=\"side-menu__sub-icon ";
            // line 184
            yield (((($tmp = ($context["attendanceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 187
            yield (((($tmp = ($context["attendanceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/attendance/create\" class=\"side-menu ";
            // line 188
            yield (((($context["currentPath"] ?? null) == "/attendance/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus\"></i></div><div class=\"side-menu__title\">Inserisci Presenze</div></a></li>
                    <li><a href=\"/attendance\" class=\"side-menu ";
            // line 189
            yield (((($context["currentPath"] ?? null) == "/attendance")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"search\"></i></div><div class=\"side-menu__title\">Cerca</div></a></li>
                    <li><a href=\"/attendance/advances\" class=\"side-menu ";
            // line 190
            yield (((($context["currentPath"] ?? null) == "/attendance/advances")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"banknote\"></i></div><div class=\"side-menu__title\">Anticipi</div></a></li>
                    <li><a href=\"/attendance/refunds\" class=\"side-menu ";
            // line 191
            yield (((($context["currentPath"] ?? null) == "/attendance/refunds")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"wallet\"></i></div><div class=\"side-menu__title\">Rimborsi</div></a></li>
                    <li><a href=\"/attendance/fines\" class=\"side-menu ";
            // line 192
            yield (((($context["currentPath"] ?? null) == "/attendance/fines")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"camera\"></i></div><div class=\"side-menu__title\">Multe</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 197
        yield "
        ";
        // line 199
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["equipment"], "method", false, false, false, 199)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 200
            yield "            ";
            $context["equipmentActive"] = (is_string($_v22 = ($context["currentPath"] ?? null)) && is_string($_v23 = "/equipment") && str_starts_with($_v22, $_v23));
            // line 201
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 202
            yield (((($tmp = ($context["equipmentActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"truck\"></i></div>
                    <div class=\"side-menu__title\">Mezzi Sollevamento
                        <div class=\"side-menu__sub-icon ";
            // line 205
            yield (((($tmp = ($context["equipmentActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 208
            yield (((($tmp = ($context["equipmentActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/equipment/assign\" class=\"side-menu ";
            // line 209
            yield (((($context["currentPath"] ?? null) == "/equipment/assign")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus-circle\"></i></div><div class=\"side-menu__title\">Inserisci Mezzi</div></a></li>
                    <li><a href=\"/equipment/rentals\" class=\"side-menu ";
            // line 210
            yield (((($context["currentPath"] ?? null) == "/equipment/rentals")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"list-checks\"></i></div><div class=\"side-menu__title\">Noleggi</div></a></li>
                    <li><a href=\"/equipment/manage\" class=\"side-menu ";
            // line 211
            yield (((($context["currentPath"] ?? null) == "/equipment/manage")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"clipboard\"></i></div><div class=\"side-menu__title\">Mezzi Sollevamento</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 216
        yield "
        ";
        // line 218
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["bookings"], "method", false, false, false, 218)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 219
            yield "            ";
            $context["bookingsActive"] = (is_string($_v24 = ($context["currentPath"] ?? null)) && is_string($_v25 = "/bookings") && str_starts_with($_v24, $_v25));
            // line 220
            yield "            <li>
                <a href=\"/bookings\" class=\"side-menu ";
            // line 221
            yield (((($tmp = ($context["bookingsActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"bookmark\"></i></div>
                    <div class=\"side-menu__title\">Prenotazioni</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 228
        yield "
        ";
        // line 230
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["pianificazione"], "method", false, false, false, 230)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 231
            yield "            <li>
                <a href=\"/pianificazione\" class=\"side-menu ";
            // line 232
            yield (((($context["currentPath"] ?? null) == "/pianificazione")) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"clipboard-list\"></i></div>
                    <div class=\"side-menu__title\">Squadre</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 239
        yield "
        ";
        // line 241
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["programmazione"], "method", false, false, false, 241)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 242
            yield "            ";
            $context["progActive"] = (is_string($_v26 = ($context["currentPath"] ?? null)) && is_string($_v27 = "/programmazione") && str_starts_with($_v26, $_v27));
            // line 243
            yield "            <li>
                <a href=\"/programmazione\" class=\"side-menu ";
            // line 244
            yield (((($tmp = ($context["progActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"truck\"></i></div>
                    <div class=\"side-menu__title\">Programmazione</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 251
        yield "
        ";
        // line 253
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["tickets"], "method", false, false, false, 253)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 254
            yield "            ";
            $context["ticketsActive"] = (is_string($_v28 = ($context["currentPath"] ?? null)) && is_string($_v29 = "/tickets") && str_starts_with($_v28, $_v29));
            // line 255
            yield "            <li>
                <a href=\"/tickets\" class=\"side-menu ";
            // line 256
            yield (((($tmp = ($context["ticketsActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"ticket\"></i></div>
                    <div class=\"side-menu__title\">Bigliettini</div>
                </a>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 263
        yield "
        ";
        // line 265
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["documents"], "method", false, false, false, 265)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 266
            yield "            ";
            $context["usersActive"] = (is_string($_v30 = ($context["currentPath"] ?? null)) && is_string($_v31 = "/users") && str_starts_with($_v30, $_v31));
            // line 267
            yield "            ";
            $context["companiesActive"] = (is_string($_v32 = ($context["currentPath"] ?? null)) && is_string($_v33 = "/companies") && str_starts_with($_v32, $_v33));
            // line 268
            yield "            ";
            $context["complianceActive"] = ((((($context["currentPath"] ?? null) == "/documents/expired") || (($context["currentPath"] ?? null) == "/documents/expired-cv")) || ($context["usersActive"] ?? null)) || ($context["companiesActive"] ?? null));
            // line 269
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 270
            yield (((($tmp = ($context["complianceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"shield-check\"></i></div>
                    <div class=\"side-menu__title\">Compliance
                        <div class=\"side-menu__sub-icon ";
            // line 273
            yield (((($tmp = ($context["complianceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 276
            yield (((($tmp = ($context["complianceActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/users\" class=\"side-menu ";
            // line 277
            yield (((($context["usersActive"] ?? null) && (($context["currentPath"] ?? null) != "/users/create"))) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"users\"></i></div><div class=\"side-menu__title\">Operai</div></a></li>
                    <li><a href=\"/companies\" class=\"side-menu ";
            // line 278
            yield (((($context["companiesActive"] ?? null) && (($context["currentPath"] ?? null) != "/companies/my"))) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"file-text\"></i></div><div class=\"side-menu__title\">Aziende</div></a></li>
                    <li><a href=\"/documents/expired\" class=\"side-menu ";
            // line 279
            yield (((($context["currentPath"] ?? null) == "/documents/expired")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"alert-triangle\"></i></div><div class=\"side-menu__title\">Documenti Scaduti</div></a></li>
                </ul>
            </li>
            <li class=\"side-nav__devider my-6\"></li>
        ";
        }
        // line 284
        yield "
        ";
        // line 286
        yield "        ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["user"] ?? null), "canAccess", ["share"], "method", false, false, false, 286)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 287
            yield "            ";
            $context["shareActive"] = ((($context["currentPath"] ?? null) == "/share") || (is_string($_v34 = ($context["currentPath"] ?? null)) && is_string($_v35 = "/share/") && str_starts_with($_v34, $_v35)));
            // line 288
            yield "            <li>
                <a href=\"javascript:;\" class=\"side-menu ";
            // line 289
            yield (((($tmp = ($context["shareActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu--active") : (""));
            yield "\">
                    <div class=\"side-menu__icon\"><i data-lucide=\"cloud\"></i></div>
                    <div class=\"side-menu__title\">Doc Condivisi
                        <div class=\"side-menu__sub-icon ";
            // line 292
            yield (((($tmp = ($context["shareActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("transform rotate-180") : (""));
            yield "\"><i data-lucide=\"chevron-down\"></i></div>
                    </div>
                </a>
                <ul class=\"";
            // line 295
            yield (((($tmp = ($context["shareActive"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("side-menu__sub-open") : (""));
            yield "\">
                    <li><a href=\"/share/create\" class=\"side-menu ";
            // line 296
            yield (((($context["currentPath"] ?? null) == "/share/create")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"plus\"></i></div><div class=\"side-menu__title\">Crea Link</div></a></li>
                    <li><a href=\"/share\" class=\"side-menu ";
            // line 297
            yield (((($context["currentPath"] ?? null) == "/share")) ? ("side-menu--active") : (""));
            yield "\"><div class=\"side-menu__icon\"><i data-lucide=\"list\"></i></div><div class=\"side-menu__title\">Lista Link</div></a></li>
                </ul>
            </li>
        ";
        }
        // line 301
        yield "
    </ul>

    <div style=\"padding:16px 20px 20px;margin-top:auto;border-top:1px solid rgba(255,255,255,.06);\">
        <div style=\"display:flex;align-items:center;gap:8px;\">
            <span style=\"font-size:10px;font-weight:800;letter-spacing:.08em;color:rgba(255,255,255,.25);text-transform:uppercase;\">BOB</span>
            <span style=\"font-size:10px;font-weight:700;padding:2px 8px;border-radius:5px;background:rgba(99,102,241,.15);color:rgba(139,92,246,.8);\">
                ";
        // line 308
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["bobVersion"] ?? null), "version", [], "any", false, false, false, 308), "html", null, true);
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["bobVersion"] ?? null), "commits", [], "any", false, false, false, 308)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield "<span style=\"opacity:.4;\">+";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["bobVersion"] ?? null), "commits", [], "any", false, false, false, 308), "html", null, true);
            yield "</span>";
        }
        // line 309
        yield "            </span>
        </div>
    </div>

</nav>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "layout/_menu.html.twig";
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
        return array (  665 => 309,  658 => 308,  649 => 301,  642 => 297,  638 => 296,  634 => 295,  628 => 292,  622 => 289,  619 => 288,  616 => 287,  613 => 286,  610 => 284,  602 => 279,  598 => 278,  594 => 277,  590 => 276,  584 => 273,  578 => 270,  575 => 269,  572 => 268,  569 => 267,  566 => 266,  563 => 265,  560 => 263,  550 => 256,  547 => 255,  544 => 254,  541 => 253,  538 => 251,  528 => 244,  525 => 243,  522 => 242,  519 => 241,  516 => 239,  506 => 232,  503 => 231,  500 => 230,  497 => 228,  487 => 221,  484 => 220,  481 => 219,  478 => 218,  475 => 216,  467 => 211,  463 => 210,  459 => 209,  455 => 208,  449 => 205,  443 => 202,  440 => 201,  437 => 200,  434 => 199,  431 => 197,  423 => 192,  419 => 191,  415 => 190,  411 => 189,  407 => 188,  403 => 187,  397 => 184,  391 => 181,  388 => 180,  385 => 179,  382 => 178,  379 => 176,  371 => 171,  367 => 170,  363 => 169,  359 => 168,  353 => 165,  347 => 162,  344 => 161,  341 => 160,  338 => 159,  335 => 157,  327 => 152,  323 => 151,  319 => 150,  313 => 147,  307 => 144,  304 => 143,  302 => 142,  293 => 136,  290 => 135,  287 => 134,  284 => 133,  281 => 131,  273 => 126,  269 => 125,  265 => 124,  259 => 121,  253 => 118,  250 => 117,  247 => 116,  244 => 115,  241 => 113,  233 => 108,  229 => 107,  225 => 106,  219 => 103,  213 => 100,  210 => 99,  207 => 98,  204 => 97,  201 => 95,  193 => 90,  189 => 89,  185 => 88,  179 => 85,  173 => 82,  170 => 81,  167 => 80,  164 => 79,  161 => 77,  148 => 67,  143 => 65,  135 => 60,  128 => 56,  125 => 55,  122 => 54,  119 => 52,  108 => 44,  105 => 43,  95 => 35,  86 => 29,  83 => 28,  80 => 27,  70 => 19,  67 => 18,  64 => 17,  61 => 15,  59 => 14,  48 => 6,  42 => 2,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "layout/_menu.html.twig", "/var/www/bob.csmontaggi.it/public/templates/layout/_menu.html.twig");
    }
}
