import './publicPath';
import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

const container = document.getElementById( 'paydarpress-app' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
