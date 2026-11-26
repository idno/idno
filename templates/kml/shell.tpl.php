<?php

    unset($vars['body']);

if (empty($vars['title']) && !empty($vars['description'])) {
    $vars['title'] = implode(' ', array_slice(explode(' ', strip_tags($vars['description'])), 0, 10));
}

    $page = new DOMDocument();
    $page->formatOutput = true;
    $kml = $page->createElement('kml');
    $kml->setAttribute('xmlns', 'http://www.opengis.net/kml/2.2');
    $document = $page->createElement('Document');

    // In case this isn't a feed page, find any objects
if (empty($vars['items']) && !empty($vars['object'])) {
    $vars['items'] = array($vars['object']);
}

    // If we have a feed, add the items
if (!empty($vars['items'])) {
    foreach($vars['items'] as $item) {
        // Where the item is, as far as whoever asks may be told
        $location = $item instanceof \Idno\Common\Entity ? $item->location() : [];
        if (!empty($location['latitude']) && !empty($location['longitude'])) {
            $kmlItem = $page->createElement('Placemark');
            if ($title = $item->getTitle()) {
                $kmlItem->appendChild($page->createElement('name', $item->getTitle()));
            }
            $description = $page->createElement('description');
            $description->appendChild($page->createCDATASection($item->draw() . '<p><small>'.date('F j, Y', $item->created).'</small></p>'));
            $kmlItem->appendChild($description);
            $point          = $page->createElement('Point');
            $point->appendChild($page->createElement('coordinates', $location['longitude'] . ',' . $location['latitude']));
            $kmlItem->appendChild($point);
            $document->appendChild($kmlItem);
        }
    }
}

    $kml->appendChild($document);
    $page->appendChild($kml);
    echo $page->saveXML();
