import { copyFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = new URL( '../', import.meta.url );
for ( const name of [ 'swiper-bundle.js', 'swiper-bundle.min.js', 'swiper-bundle.css', 'swiper-bundle.min.css', 'LICENSE' ] ) {
	copyFileSync( fileURLToPath( new URL( `node_modules/swiper/${ name }`, root ) ), fileURLToPath( new URL( `assets/lib/swiper/${ name }`, root ) ) );
}
