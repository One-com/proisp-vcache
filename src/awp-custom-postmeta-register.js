const { registerPlugin } = wp.plugins;

import Onecom_Exclude_Cache_Plugin from './awp-custom-postmeta-fields';

registerPlugin( 'onecom-exclude-cache-plugin', {
    render() {
        return(<Onecom_Exclude_Cache_Plugin />);
    }
} );