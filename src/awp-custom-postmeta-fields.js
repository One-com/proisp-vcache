const { __ } = wp.i18n;
const { compose } = wp.compose;
const { withSelect, withDispatch } = wp.data;

const { PluginDocumentSettingPanel } = wp.editPost;
const { ToggleControl, PanelRow } = wp.components;

const Onecom_Exclude_Cache_Plugin = ( { postType, postMeta, setPostMeta } ) => {
    if ( 'post' !== postType && 'page' !== postType ) return null;  // Will only render component for post type 'post' & 'page'
    return(
        <PluginDocumentSettingPanel title={ __( 'Performance Cache', 'proisp-vcache') } icon="performance" initialOpen="true">
                <ToggleControl
                    label={ blockObject.label }
                    help={
                        postMeta._oct_exclude_from_cache
                            ? sprintf(blockObject.excludeText,postType)
                            : sprintf(blockObject.includeText,postType)
                    }
                    onChange={ ( value ) => { if(!value)value=0; setPostMeta( { _oct_exclude_from_cache: value } ) } }
                    checked={ postMeta._oct_exclude_from_cache }
                />
        </PluginDocumentSettingPanel>
    );
}

export default compose( [
    withSelect( ( select ) => {
        return {
            postMeta: select( 'core/editor' ).getEditedPostAttribute( 'meta' ),
            postType: select( 'core/editor' ).getCurrentPostType(),
        };
    } ),
    withDispatch( ( dispatch ) => {
        return {
            setPostMeta( newMeta ) {
                dispatch( 'core/editor' ).editPost( { meta: newMeta } );
            }
        };
    } )
] )( Onecom_Exclude_Cache_Plugin );