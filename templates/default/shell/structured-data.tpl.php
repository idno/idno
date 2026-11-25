<?php

$currentPage = \Idno\Core\Idno::site()->currentPage();
$pageOwner = $currentPage->getOwner();

if (!empty($vars['object'])) {
    $objectIcon = $vars['object']->getIcon();
} else {
    $objectIcon = false;
}

// Default to a webpage (https://jsonld.com/web-page/)
$jsonld = [
    "@context" => "http://schema.org",
    "@type" => "WebSite",
    "url" => $currentPage->currentUrl(),
    "name" => $vars['title'],
    "description" => $vars['description'],
    "publisher" => \Idno\Core\Idno::site()->config()->title,
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => \Idno\Core\Idno::site()->config()->getDisplayURL() . "content/all/?q={search_term}",
        "query-input" => "required name=search_term"
    ]
];
if (!empty($pageOwner)) {
    $jsonld['author'] = [
      "@type" => "Person",
      "name" => $pageOwner->getName()
    ];
}

// We're a permalink, so use specific permalink info
if ($currentPage->isPermalink()) {
    if (!empty($vars['user']) && $vars['user'] instanceof Idno\Entities\User) {

        $object = $vars['user'];
        if (is_callable([$object, 'jsonLDSerialise'])) {

            $jsonld = $object->jsonLDSerialise();

        }
    }
    if (!empty($vars['object']) && $currentPage->isPermalinkFor($vars['object'])) {

        $object = $vars['object'];
        if (is_callable([$object, 'jsonLDSerialise'])) {

            $jsonld = $object->jsonLDSerialise();

        }
    }


}

if (!empty($jsonld)) {
    ?>

<!-- JSON+LD Structured Data -->
<script type="application/ld+json">
    <?php echo json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>
    
</script>
    <?php
}
