<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\PromptLibrary;

/**
 * The prompt library: ready-made things to say to an agent.
 *
 * Elementor MCP exposes a broad typed ability surface, and the hardest part of using it is not the
 * connection. It is knowing what to ask for. "Rewrite my homepage" gets a
 * shrug; a prompt that names the page, the tone, the constraints and the
 * approval step gets work done. This library is that difference, written down.
 *
 * Not to be confused with ElementorMCP\Skills\Prompts, which registers MCP
 * prompt-mode skills for the protocol. These are for humans: copy, paste into
 * your own client, edit to taste.
 *
 * Free ships exactly ten industry starter packs. Pro adds deeper Elementor,
 * industry, commerce, and SEO packs through the
 * `elementor_mcp_prompt_packs` filter, so this file never learns Pro exists
 * and keeps working unchanged when it is absent.
 *
 * To add prompts: hook the
 * filter from anywhere else. A pack is data: slug, label, group, and a list of
 * prompts, with no behaviour to keep in step.
 */

if (!defined('ABSPATH')) {
    exit();
}

const PAGE = 'elementor-mcp-prompts';

/**
 * Pack groups, in display order.
 *
 * People enter the library from three directions: the editor/theme they use,
 * the industry they serve, or the job they need done. Industry prompts still
 * require the target builder as an explicit field, so choosing an industry can
 * never silently produce foreign editor data.
 *
 * @return array<string, string>
 */
function groups(): array
{
    return [
        'builder' => __('By editor', domain: 'elementor-mcp'),
        'industry' => __('By industry', domain: 'elementor-mcp'),
        'workflow' => __('By task', domain: 'elementor-mcp'),
    ];
}

/**
 * Every prompt pack available on this site.
 *
 * @return list<array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}>
 */
function packs(): array
{
    $packs = industry_starter_packs();

    /**
     * Filter the prompt library.
     *
     * Add a pack: slug, label, group (see groups()), description, pro flag, and
     * a list of prompts with title, description and prompt text.
     *
     * @param list<array<string, mixed>> $packs
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('elementor_mcp_prompt_packs', $packs);
    if (!is_array($filtered)) {
        return $packs;
    }

    $safe = [];
    /** @var mixed $pack */
    foreach ($filtered as $pack) {
        $clean = normalize_pack($pack);
        if ($clean !== null) {
            $safe[] = $clean;
        }
    }

    return $safe;
}

/**
 * Coerce a filtered pack into the documented shape, or reject it.
 *
 * Anything can hook the filter, so nothing downstream should have to guess
 * whether a key is present or a prompt is a string. A malformed pack is dropped
 * rather than half-rendered.
 *
 * @param mixed $pack
 * @return array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}|null
 */
function normalize_pack(mixed $pack): ?array
{
    if (!is_array($pack)) {
        return null;
    }

    $slug = is_string($pack['slug'] ?? null) ? $pack['slug'] : '';
    $label = is_string($pack['label'] ?? null) ? $pack['label'] : '';
    if ($slug === '' || $label === '') {
        return null;
    }

    $group = is_string($pack['group'] ?? null) ? $pack['group'] : 'workflow';
    if (!array_key_exists($group, groups())) {
        $group = 'workflow';
    }

    $prompts = normalize_prompts($pack['prompts'] ?? null);
    if ($prompts === []) {
        return null;
    }

    return [
        'slug' => $slug,
        'label' => $label,
        'group' => $group,
        'description' => is_string($pack['description'] ?? null) ? $pack['description'] : '',
        'pro' => ($pack['pro'] ?? false) === true,
        'prompts' => $prompts,
    ];
}

/**
 * Coerce a pack's prompt list, dropping anything unusable.
 *
 * A prompt with no text is not a prompt, and one with no title cannot be shown
 * or copied, so both are dropped rather than rendered as an empty card.
 *
 * @param mixed $raw_prompts
 * @return list<array{title: string, description: string, prompt: string}>
 */
function normalize_prompts(mixed $raw_prompts): array
{
    $prompts = [];

    /** @var mixed $raw */
    foreach (is_array($raw_prompts) ? $raw_prompts : [] as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $text = is_string($raw['prompt'] ?? null) ? trim($raw['prompt']) : '';
        $title = is_string($raw['title'] ?? null) ? $raw['title'] : '';
        if ($text === '' || $title === '') {
            continue;
        }

        $prompts[] = [
            'title' => $title,
            'description' => is_string($raw['description'] ?? null) ? $raw['description'] : '',
            'prompt' => $text,
        ];
    }

    return $prompts;
}

/**
 * The ten industries represented in Free.
 *
 * Each record supplies the real trust signal and claim boundary that keeps its
 * starter materially different from a generic "make me a website" prompt.
 *
 * @return array<string, array{label: string, audience: string, proof: string, guardrail: string, structure: string, signature: string, media: string}>
 */
function free_industry_catalog(): array
{
    return [
        'restaurant' => [
            'label' => __('Restaurant & Cafe', domain: 'elementor-mcp'),
            'audience' => __('diners choosing where to eat, order, or reserve', domain: 'elementor-mcp'),
            'proof' => __('current menu, prices, hours, location, booking method, dietary process, and original food photography', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent menu items, allergens, prices, awards, opening hours, delivery areas, or reservation availability.', domain: 'elementor-mcp'),
            'structure' => __('Opening with cuisine, place, and current service state; menu highlights; full menu route; dietary process; chef or origin story; atmosphere; real reviews; reservations or ordering; visit details; closing action.', domain: 'elementor-mcp'),
            'signature' => __('Treat the page like a contemporary menu and dining-room sequence: editorial food scale, intentional white space, course-like pacing, and a visible hours/reservation rail. Avoid SaaS feature cards and generic fork-and-knife decoration.', domain: 'elementor-mcp'),
            'media' => __('Use licensed photographs of the actual dishes, room, team, pass, and exterior with a consistent natural-light grade; never substitute unrelated stock food for a named menu item.', domain: 'elementor-mcp'),
        ],
        'dental' => [
            'label' => __('Dental Clinic', domain: 'elementor-mcp'),
            'audience' => __('new and returning patients comparing suitable dental care', domain: 'elementor-mcp'),
            'proof' => __('verified dentist profiles, registrations, treatments, fee basis, location, accessibility, and emergency process', domain: 'elementor-mcp'),
            'guardrail' => __('Never promise clinical outcomes or invent qualifications, treatment suitability, finance terms, reviews, or before-and-after evidence.', domain: 'elementor-mcp'),
            'structure' => __('Patient-focused opening; treatment routes; new-patient process; clinician profiles; fees and finance facts; anxiety and accessibility support; emergency guidance; verified patient feedback; location and appointment action.', domain: 'elementor-mcp'),
            'signature' => __('Use calm clinical clarity, generous breathing room, a patient-journey sequence, and precise treatment navigation. Trust should come from named people and explained process, not tooth icons, glossy promises, or dramatic cosmetic imagery.', domain: 'elementor-mcp'),
            'media' => __('Use consented clinic, clinician, equipment, accessibility, and patient-environment photography; do not fabricate patients or use before-and-after media without documented consent and context.', domain: 'elementor-mcp'),
        ],
        'legal' => [
            'label' => __('Law Firm', domain: 'elementor-mcp'),
            'audience' => __('people and businesses seeking help in a stated jurisdiction', domain: 'elementor-mcp'),
            'proof' => __('named lawyers, bar or professional registrations, practice areas, locations, languages, consultation process, and reviewed experience', domain: 'elementor-mcp'),
            'guardrail' => __('Never guarantee a result, invent case outcomes or credentials, or present general content as personal legal advice.', domain: 'elementor-mcp'),
            'structure' => __('Jurisdiction and fit at the opening; practice-area routes; how engagement works; named lawyer profiles; representative experience only when approved; resources and disclaimers; office details; consultation action.', domain: 'elementor-mcp'),
            'signature' => __('Use an editorial case-brief rhythm with strong typographic hierarchy, disciplined rules, and restrained document cues. Avoid courthouse clichés, gavels, scales, generic handshake photos, and outcome-led sales language.', domain: 'elementor-mcp'),
            'media' => __('Prefer real lawyer, office, community, and working-session photography with permissions; use diagrams or document details only when they explain a process and do not expose client information.', domain: 'elementor-mcp'),
        ],
        'real-estate' => [
            'label' => __('Real Estate Agency', domain: 'elementor-mcp'),
            'audience' => __('buyers, sellers, landlords, and renters moving through distinct property journeys', domain: 'elementor-mcp'),
            'proof' => __('licensed agents, authoritative listings, service areas, dated market evidence, office details, and a clear valuation or enquiry path', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent listings, prices, availability, yields, valuations, licences, neighborhood facts, or travel times.', domain: 'elementor-mcp'),
            'structure' => __('Journey split for buy, sell, rent, or manage; authoritative featured listings; area expertise; agent proof; process; dated market evidence; valuation or enquiry flow; office and service-area details.', domain: 'elementor-mcp'),
            'signature' => __('Make the property search and decision path the composition: cinematic but truthful listing media, map or area rhythm, data used sparingly, and clear journey switches. Avoid luxury clichés when the inventory does not support them.', domain: 'elementor-mcp'),
            'media' => __('Use current feed imagery and approved agent or neighborhood photography; preserve listing attribution and never use a property image to imply an available listing when it is illustrative.', domain: 'elementor-mcp'),
        ],
        'fitness' => [
            'label' => __('Fitness Studio', domain: 'elementor-mcp'),
            'audience' => __('prospective members choosing classes, coaching, facilities, and membership options', domain: 'elementor-mcp'),
            'proof' => __('real timetable, qualified trainers, class scope, facilities, pricing, accessibility, cancellation policy, and consented testimonials', domain: 'elementor-mcp'),
            'guardrail' => __('Never promise weight loss, healing, availability, or fabricated transformations, qualifications, schedules, or prices.', domain: 'elementor-mcp'),
            'structure' => __('Goal and fit at the opening; class or coaching routes; live schedule; trainer profiles; facilities and accessibility; membership and cancellation facts; consented community proof; first-visit or booking action.', domain: 'elementor-mcp'),
            'signature' => __('Use movement, cadence, and timetable structure as the visual system: bold directional type, disciplined energy, and real participation. Avoid transformation clichés, body shaming, neon-on-black sameness, and fake performance metrics.', domain: 'elementor-mcp'),
            'media' => __('Use consented photographs of real classes, trainers, equipment, and facilities across varied bodies and ability levels; never present a stock transformation as a client result.', domain: 'elementor-mcp'),
        ],
        'home-services' => [
            'label' => __('Home Services', domain: 'elementor-mcp'),
            'audience' => __('homeowners comparing a trustworthy local contractor or trade', domain: 'elementor-mcp'),
            'proof' => __('real projects, service radius, licences and insurance where required, process, response policy, workmanship terms, and verified reviews', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent licences, insurance, response times, guarantees, prices, availability, project photos, or review totals.', domain: 'elementor-mcp'),
            'structure' => __('Service and area fit at the opening; service list; urgent versus scheduled route; real project evidence; how the job works; credentials; workmanship terms; verified reviews; quote form; hours and contact.', domain: 'elementor-mcp'),
            'signature' => __('Build the composition from jobsite evidence: material textures through photography, project-led before/process/after stories, clear service-area bands, and a practical estimate path. Avoid tool-icon grids and invented emergency urgency.', domain: 'elementor-mcp'),
            'media' => __('Use owned or licensed project, team, vehicle, equipment, and finished-work photography with accurate captions; do not pass stock work off as a completed client project.', domain: 'elementor-mcp'),
        ],
        'nonprofit' => [
            'label' => __('Nonprofit & Community', domain: 'elementor-mcp'),
            'audience' => __('beneficiaries, donors, volunteers, members, and partners who need different routes', domain: 'elementor-mcp'),
            'proof' => __('registration and governance, current programs, sourced impact, safeguarding, financial reports, donation handling, and responsible contacts', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent impact, beneficiary stories, charity status, endorsements, event dates, donation use, or financial figures.', domain: 'elementor-mcp'),
            'structure' => __('Mission and current need; program routes; sourced impact; who is served; transparent use of funds; stories with consent; governance and safeguarding; volunteer, partner, or donation paths; reports and contact.', domain: 'elementor-mcp'),
            'signature' => __('Let programs and accountable impact shape the page: an evidence ledger, human story sequence, transparent funding cues, and several clearly distinct ways to participate. Avoid pity imagery, vague inspiration, and unsupported impact counters.', domain: 'elementor-mcp'),
            'media' => __('Use dignity-preserving, consented program and community media with captions and context; never stage or appropriate beneficiary stories, and identify when privacy requires illustration instead.', domain: 'elementor-mcp'),
        ],
        'education' => [
            'label' => __('Education & Training', domain: 'elementor-mcp'),
            'audience' => __('learners and decision-makers comparing programs, workload, delivery, support, and outcomes', domain: 'elementor-mcp'),
            'proof' => __('curriculum, named instructors, verified credentials, prerequisites, delivery mode, workload, fees, dates, support, and refund policy', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent accreditation, job outcomes, pass rates, qualifications, cohort dates, prices, testimonials, or available seats.', domain: 'elementor-mcp'),
            'structure' => __('Learner fit and outcome boundary; program routes; curriculum and workload; delivery and dates; instructor proof; admissions or enrolment; fees and refund facts; learner support; real evidence; next-cohort action.', domain: 'elementor-mcp'),
            'signature' => __('Use curriculum progression as the composition: modular learning paths, syllabus rhythm, instructor annotations, and clear workload and decision checkpoints. Avoid diploma imagery, fake graduation outcomes, and generic course-card walls.', domain: 'elementor-mcp'),
            'media' => __('Use real instructors, learning environments, course artifacts, and consented learner activity; never imply accreditation, a campus, equipment, or career result through unrelated stock media.', domain: 'elementor-mcp'),
        ],
        'ecommerce' => [
            'label' => __('E-commerce & Retail', domain: 'elementor-mcp'),
            'audience' => __('shoppers comparing products, fit, delivery, returns, and support before purchasing', domain: 'elementor-mcp'),
            'proof' => __('authoritative product data, original media, real reviews, current stock and price, shipping, returns, payments, and company identity', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent price, stock, reviews, materials, specifications, delivery windows, discounts, guarantees, or return terms.', domain: 'elementor-mcp'),
            'structure' => __('Brand and category opening; purposeful category routes; real featured products; buying guidance; product proof and reviews; shipping and returns; support; company identity; newsletter only with value; shop action.', domain: 'elementor-mcp'),
            'signature' => __('Let product characteristics determine the visual system: editorial product scale, useful comparison, tactile detail, and confident commerce states. Avoid fake scarcity, badge clutter, generic lifestyle imagery, and identical product-card repetition.', domain: 'elementor-mcp'),
            'media' => __('Use authoritative product angles, scale, detail, packaging, and real-use media with consistent crops; never substitute a different variant or use lifestyle imagery that misrepresents what is sold.', domain: 'elementor-mcp'),
        ],
        'saas' => [
            'label' => __('SaaS & Software', domain: 'elementor-mcp'),
            'audience' => __('buyers and users evaluating product fit, integrations, security, pricing, and support', domain: 'elementor-mcp'),
            'proof' => __('current product behavior, screenshots, supported integrations, security controls, pricing, documentation, status evidence, and real customer stories', domain: 'elementor-mcp'),
            'guardrail' => __('Never invent features, AI behavior, integrations, certifications, compliance, uptime, customer counts, performance, or pricing.', domain: 'elementor-mcp'),
            'structure' => __('Audience and product fit; real workflow demonstration; use-case routes; feature depth; integrations; security and reliability evidence; pricing; documentation; approved customer proof; demo or trial action.', domain: 'elementor-mcp'),
            'signature' => __('Use the real workflow as the composition: annotated interface sequences, input-to-outcome storytelling, technical detail at decision points, and restrained motion that explains state. Avoid fake dashboards, floating glass cards, and decorative AI motifs.', domain: 'elementor-mcp'),
            'media' => __('Use current product captures, diagrams based on real architecture, and approved customer or team media; label conceptual visuals and never show a capability the product does not perform.', domain: 'elementor-mcp'),
        ],
    ];
}

/**
 * Exactly ten Free prompt packs, one useful starter per industry.
 *
 * @return list<array{slug: string, label: string, group: string, description: string, pro: bool, prompts: list<array{title: string, description: string, prompt: string}>}>
 */
function industry_starter_packs(): array
{
    $packs = [];
    foreach (free_industry_catalog() as $slug => $industry) {
        $label = $industry['label'];
        $packs[] = [
            'slug' => 'free-industry-' . $slug,
            'label' => $label,
            'group' => 'industry',
            'description' => sprintf(
                __('A safe Elementor homepage starter for %s.', domain: 'elementor-mcp'),
                $label,
            ),
            'pro' => false,
            'prompts' => [[
                'title' => sprintf(__('%s homepage starter', domain: 'elementor-mcp'), $label),
                'description' => sprintf(
                    __('Plan and build one evidence-led draft homepage for %s.', domain: 'elementor-mcp'),
                    $label,
                ),
                'prompt' => sprintf(
                    __(
                        "PAGE BUILDER\nElementor. Use native Elementor containers, widgets, templates, global styles, classes, and variables supported by the installed version. Raw HTML, custom CSS, custom JavaScript, and generated plugin code are last resorts for a micro-detail that cannot be achieved natively; never use them to build whole sections.\n\nROLE AND OUTCOME\nAct as a senior %1\$s web designer, conversion strategist, content designer, accessibility reviewer, and native Elementor implementer. Design and build a complete, production-quality homepage draft for [BUSINESS NAME] serving [LOCATION OR MARKET]. You own the design decisions, but not the business facts. The audience is %2\$s. Do not reproduce a generic centered hero followed by identical cards; form an industry-specific visual point of view and make every section earn its place.\n\nINDUSTRY FACT CONTRACT\nExpected evidence: %3\$s. Claim boundary: %4\$s Put [CONFIRM: exact missing fact] exactly where an unresolved claim, credential, price, date, person, policy, location, right, review, recipient, or integration would appear. Do not hide missing facts in a final footnote and do not replace them with plausible copy.\n\nDISCOVERY BEFORE DESIGN\nInspect the active Elementor and WordPress versions, theme, existing homepage and templates, navigation, global colors and fonts, reusable components, content types, forms, contact routes, SEO and analytics providers, media library, performance constraints, and writable abilities. Read the live schema for every native widget you intend to use. Inventory supplied brand assets, licensed typefaces, current copy, people, proof, policies, imagery, and conversion destinations. If web research is available, review two or three strong current %1\$s sites for patterns and gaps, but do not copy wording, structure, trade dress, code, or proprietary assets. Return only decisions or missing inputs that would materially change the work.\n\nINFORMATION AND SECTION BLUEPRINT\nThe recommended content sequence is: %5\$s Adapt it to the real business and omit any section without credible content. Before writing, present a section map. For each section state its visitor question, source evidence, layout idea, mobile order, primary or secondary action, and internal link. Define exactly one H1, a clear opening promise with boundaries, one dominant conversion action, and useful alternate routes for visitors who are not ready. Preserve one coherent narrative rather than assembling fashionable components.\n\nDESIGN SYSTEM\nPropose a compact style guide before building: semantic palette with contrast intent; display, body, and interface type roles with licensed or safe fallbacks; fluid type scale; spacing rhythm; content widths and grid; image ratios; borders, radii, shadows, icon treatment, and motion rules. Derive these choices from the actual brand and industry instead of imposing a default palette. The design signature is: %6\$s Use scale contrast, editorial pacing, asymmetry, alignment, and real content to create distinction. Avoid gradients, blobs, glass effects, card spam, excessive centered copy, or animation unless they genuinely fit the approved art direction.\n\nCONTENT AND TRUST\nWrite complete working copy from verified inputs. State who the offer is for and not for, what is included and excluded, the process, evidence, risks or limitations, price or fee basis only when supplied, and the next step. Keep important qualifications next to the relevant claim. Do not invent people, credentials, clients, reviews, ratings, awards, metrics, availability, policies, legal or health outcomes, product behavior, or structured-data facts. Use only schema supported by visible current content and never promise rich results.\n\nIMAGES AND ICONS\nMedia direction: %7\$s Every image slot must have an actual approved or licensed asset, a purpose, aspect ratio, crop or focal guidance, dimensions, alt-text decision, caption or credit when needed, and rights status. Never use a placeholder URL or present stock media as a real person, project, property, product, result, or location. Use one consistent icon system and semantic SVGs or native icons; no emoji and no mixed icon families.\n\nCONVERSION AND FORMS\nMap every button and link to an authoritative destination. If the page needs a form, inspect the installed native form capability first and specify purpose, minimum fields, visible labels, required status, instructions, consent, spam controls, validation, error, success, storage, recipients, retention, and test method. If no safe native form exists, use a clearly labeled phone, email, booking, shop, donate, or demo route instead of a fake form. Never submit a live enquiry, send a notification, call a webhook, create an account, reserve inventory, or transact without explicit approval.\n\nRESPONSIVE AND ACCESSIBILITY ACCEPTANCE\nDesign deliberately for wide desktop, laptop, tablet, and narrow mobile. Specify content order, navigation, wrapping, touch targets, image crops, long text, tables, accordions, sticky elements, and form behavior. Meet WCAG AA contrast against the actual background; preserve landmarks, logical headings, keyboard operation, visible focus, associated labels, useful errors, zoom and reflow, reduced motion, and screen-reader names. Decorative images use empty alt text; informative text describes purpose, not keywords. Never hide meaningful information on mobile.\n\nPERFORMANCE AND QUALITY\nReserve media dimensions, use responsive image sizes, protect the likely LCP image, lazy-load below-the-fold media, minimize font weights and third-party scripts, and avoid layout-shift sources and excessive DOM depth. Do not promise Core Web Vitals without field evidence. Fully populate and verify one section before moving to the next; empty widgets, filler copy, missing images, dead buttons, and placeholder data are release failures.\n\nSAFE BUILD, VERIFICATION, AND HANDOFF\nWait for approval on the section map and style guide. Then create a new draft or isolated template; preserve the live homepage, header, footer, menu, forms, global templates, redirects, and indexing. Verify the rendered result at representative desktop, tablet, and mobile widths, including overflow, headings, keyboard focus, contrast, links, images, validation states without live submission, console errors, and Elementor editability. Return edit and preview links, sections and widgets built, tokens reused or proposed, evidence sources, checks performed, unresolved [CONFIRM] items, deviations, rollback reference, and the exact approvals required before publication. Do not publish.",
                        domain: 'elementor-mcp',
                    ),
                    $label,
                    $industry['audience'],
                    $industry['proof'],
                    $industry['guardrail'],
                    $industry['structure'],
                    $industry['signature'],
                    $industry['media'],
                ),
            ]],
        ];
    }

    return $packs;
}
