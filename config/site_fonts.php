<?php

/*
|--------------------------------------------------------------------------
| Fonts a restaurant website may use (Site builder > Site > Fonts)
|--------------------------------------------------------------------------
|
| Google Fonts, by their exact family name. The builder only offers these,
| and the theme is checked against this list, so a family name is always one
| the site can load. Each entry: category (sans-serif, serif, display,
| handwriting - the fallback while it loads), the weights to load (Google
| refuses the whole request if one is asked for that the family lacks), and
| whether it has Bangla letters.
|
| Pairing suggestions live in the front (lib/site/fonts.ts).
*/

$f = fn (string $category, string $weights = '400;700', bool $bangla = false) => compact('category', 'weights', 'bangla');

return [
    // Sans-serif
    'Inter' => $f('sans-serif'),
    'Poppins' => $f('sans-serif'),
    'Montserrat' => $f('sans-serif'),
    'Lato' => $f('sans-serif'),
    'Open Sans' => $f('sans-serif'),
    'Roboto' => $f('sans-serif'),
    'Nunito' => $f('sans-serif'),
    'Nunito Sans' => $f('sans-serif'),
    'Raleway' => $f('sans-serif'),
    'Work Sans' => $f('sans-serif'),
    'DM Sans' => $f('sans-serif'),
    'Manrope' => $f('sans-serif'),
    'Outfit' => $f('sans-serif'),
    'Plus Jakarta Sans' => $f('sans-serif'),
    'Rubik' => $f('sans-serif'),
    'Karla' => $f('sans-serif'),
    'Mulish' => $f('sans-serif'),
    'Josefin Sans' => $f('sans-serif'),
    'Quicksand' => $f('sans-serif'),
    'Barlow' => $f('sans-serif'),
    'Source Sans 3' => $f('sans-serif'),
    'Figtree' => $f('sans-serif'),
    'Urbanist' => $f('sans-serif'),
    'Lexend' => $f('sans-serif'),
    'Space Grotesk' => $f('sans-serif'),
    'Comfortaa' => $f('sans-serif'),

    // Serif
    'Playfair Display' => $f('serif'),
    'Lora' => $f('serif'),
    'Merriweather' => $f('serif'),
    'Cormorant Garamond' => $f('serif'),
    'EB Garamond' => $f('serif'),
    'Libre Baskerville' => $f('serif'),
    'DM Serif Display' => $f('serif', '400'),
    'Crimson Pro' => $f('serif'),
    'Fraunces' => $f('serif'),
    'Cinzel' => $f('serif'),
    'Prata' => $f('serif', '400'),
    'Marcellus' => $f('serif', '400'),
    'Bodoni Moda' => $f('serif'),
    'Spectral' => $f('serif'),
    'Noto Serif' => $f('serif'),
    'Yeseva One' => $f('serif', '400'),

    // Display
    'Abril Fatface' => $f('display', '400'),
    'Bebas Neue' => $f('display', '400'),
    'Oswald' => $f('display'),
    'Anton' => $f('display', '400'),
    'Archivo Black' => $f('display', '400'),
    'Righteous' => $f('display', '400'),
    'Lobster' => $f('display', '400'),
    'Fredoka' => $f('display'),
    'Baloo 2' => $f('display'),
    'Alfa Slab One' => $f('display', '400'),
    'Bungee' => $f('display', '400'),

    // Handwriting
    'Pacifico' => $f('handwriting', '400'),
    'Satisfy' => $f('handwriting', '400'),
    'Dancing Script' => $f('handwriting'),
    'Great Vibes' => $f('handwriting', '400'),
    'Caveat' => $f('handwriting'),
    'Amatic SC' => $f('handwriting'),
    'Kaushan Script' => $f('handwriting', '400'),

    // Bangla
    'Hind Siliguri' => $f('sans-serif', '400;700', true),
    'Noto Sans Bengali' => $f('sans-serif', '400;700', true),
    'Noto Serif Bengali' => $f('serif', '400;700', true),
    'Anek Bangla' => $f('sans-serif', '400;700', true),
    'Baloo Da 2' => $f('display', '400;700', true),
    'Tiro Bangla' => $f('serif', '400', true),
    'Galada' => $f('display', '400', true),
    'Mina' => $f('sans-serif', '400;700', true),
    'Atma' => $f('display', '400;700', true),
];
