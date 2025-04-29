<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect to your site by animating sections using GSAP.
Version: 1.0
Author: Mike Mesenbring
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

function dcn_enqueue_assets() {
    // Register and enqueue inline CSS
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );

    // Enqueue GSAP
    wp_enqueue_script( 'dcn-gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', array(), '3.12.2', true );

    // Register and enqueue inline JS
    wp_register_script( 'dcn-script', false, array( 'dcn-gsap' ), null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );

function dcn_inline_css() {
    return "
html, body { height: 100%; overflow: hidden; margin: 0; perspective: 1000px; }
.dcn-section { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform-style: preserve-3d; opacity: 0; }
.dcn-section.current { opacity: 1; }
";
}

function dcn_inline_js() {
    return "
document.addEventListener('DOMContentLoaded', function(){
    const sections = {};
    const all = document.querySelectorAll('.dcn-section');
    all.forEach((s, idx) => {
        sections[s.id] = s;
        if (idx === 0) s.classList.add('current');
    });
    document.querySelectorAll('nav a[href^=\"#\"]').forEach(link => {
        link.addEventListener('click', e => {
            const targetID = link.getAttribute('href').substring(1);
            if (sections[targetID]) {
                e.preventDefault();
                const current = document.querySelector('.dcn-section.current');
                const next    = sections[targetID];
                gsap.to(current, { z: 300, opacity: 0, duration: 1 });
                gsap.fromTo(next, { z: -300, opacity: 0 }, {
                    z: 0,
                    opacity: 1,
                    duration: 1,
                    onStart: () => next.classList.add('current'),
                    onComplete: () => current.classList.remove('current')
                });
                history.pushState(null, '', '#'+targetID);
            }
        });
    });
});";
}
