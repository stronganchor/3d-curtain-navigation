<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect to your site by animating sections using GSAP.
Version: 1.1
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enqueue GSAP and our inline styles and scripts
 */
function dcn_enqueue_assets() {
    // Register and enqueue inline CSS
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );

    // Enqueue GSAP core
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', array(), '3.12.2', true );

    // Register and enqueue inline JS
    wp_register_script( 'dcn-script', false, array( 'dcn-gsap' ), null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );

/**
 * Basic full-screen 3D container styles
 */
function dcn_inline_css() {
    return "
html, body { height: 100%; overflow: hidden; margin: 0; perspective: 800px; }
.dcn-section { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform-style: preserve-3d; opacity: 0; }
.dcn-section.current { opacity: 1; }
";
}

/**
 * Bind click handlers to any menu links (<nav> anchors) that reference #anchors
 */
function dcn_inline_js() {
    return "
(function(){
    document.addEventListener('DOMContentLoaded', function(){
        // Collect all sections by ID
        var sections = {};
        document.querySelectorAll('.dcn-section').forEach(function(sec, idx){
            sections[sec.id] = sec;
            if(idx === 0) sec.classList.add('current');
        });

        // Bind to all <nav> links with hash targets
        document.querySelectorAll('nav a').forEach(function(link){
            if(!link.hash) return;
            var targetID = link.hash.replace('#','');
            if(!sections[targetID]) return;

            link.addEventListener('click', function(e){
                e.preventDefault();
                var current = document.querySelector('.dcn-section.current');
                var next    = sections[targetID];
                // Animate current out
                gsap.to(current, { z: 300, opacity: 0, duration: 1 });
                // Animate next in
                gsap.fromTo(next,
                    { z: -300, opacity: 0 },
                    {
                        z: 0,
                        opacity: 1,
                        duration: 1,
                        onStart: function(){ next.classList.add('current'); },
                        onComplete: function(){ current.classList.remove('current'); }
                    }
                );
                // Update URL
                history.pushState(null,'','#'+targetID);
            });
        });
    });
})();";
}
