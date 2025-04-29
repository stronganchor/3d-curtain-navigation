<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect to your site by animating sections using GSAP. Automatically rewrites your primary navigation links so no menu markup changes are needed.
Version: 1.2
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rewrite internal nav menu links to hashed anchors (no menu edits required)
 */
add_filter( 'nav_menu_link_attributes', 'dcn_filter_menu_links', 10, 4 );
function dcn_filter_menu_links( $atts, $item, $args, $depth ) {
    if ( empty( $atts['href'] ) ) return $atts;
    // Skip external and already-hash URLs
    if ( strpos( $atts['href'], '#' ) === 0 ) return $atts;
    $home = home_url();
    $href = $atts['href'];
    // Resolve relative URLs
    if ( strpos( $href, '/' ) === 0 ) {
        $url = $home . $href;
    } elseif ( strpos( $href, $home ) === 0 ) {
        $url = $href;
    } else {
        return $atts;
    }
    // Extract slug from path
    $path = parse_url( $url, PHP_URL_PATH );
    $slug = trim( $path, '/' );
    if ( '' === $slug ) $slug = 'home';
    // Store original for history update
    $atts['data-dcn-url'] = esc_url( $url );
    // Rewrite href to our section anchor
    $atts['href'] = '#' . sanitize_html_class( $slug );
    return $atts;
}

/**
 * Enqueue GSAP + inline CSS & JS
 */
function dcn_enqueue_assets() {
    // CSS
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );
    // GSAP
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', array(), '3.12.2', true );
    // Inline JS
    wp_register_script( 'dcn-script', false, array( 'dcn-gsap' ), null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );

/**
 * Full-screen 3D container CSS
 */
function dcn_inline_css() {
    return "
html, body { height: 100%; overflow: hidden; margin: 0; perspective: 800px; }
.dcn-section { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform-style: preserve-3d; opacity: 0; }
.dcn-section.current { opacity: 1; }
";
}

/**
 * Core JS: animate sections, update history to original URL
 */
function dcn_inline_js() {
    return "(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var sections = {};
        document.querySelectorAll('.dcn-section').forEach(function(sec, idx){
            var id = sec.id || 'home';
            sections[id] = sec;
            if(idx === 0) sec.classList.add('current');
        });
        document.querySelectorAll('nav a').forEach(function(link){
            // We now rewrite all internal links, so if hash matches a section, bind click
            var targetID = link.getAttribute('href').replace('#','');
            if(!sections[targetID]) return;
            link.addEventListener('click', function(e){
                e.preventDefault();
                var current = document.querySelector('.dcn-section.current');
                var next    = sections[targetID];
                gsap.to(current, { z: 300, opacity: 0, duration: 1 });
                gsap.fromTo(next, { z: -300, opacity: 0 }, {
                    z: 0,
                    opacity: 1,
                    duration: 1,
                    onStart: function(){ next.classList.add('current'); },
                    onComplete: function(){ current.classList.remove('current'); }
                });
                // push to original URL if stored
                var dest = link.dataset.dcnUrl || '#'+targetID;
                history.pushState(null, '', dest);
            });
        });
    });
})();";
}
