/*global VuFind, finna, L */
finna.organisationMap = (function finnaOrganisationMap() {
  var zoomLevel = {initial: 27, far: 5, close: 14};
  var holder = null;
  var mapTileUrl = null;
  var attribution = null;
  var map = null;
  var mapMarkers = {};
  var markers = [];
  var selectedMarker = null;

  /**
   * Reset the map leaflet
   */
  function reset() {
    var group = new L.featureGroup(markers);
    var bounds = group.getBounds().pad(0.2);
    // Fit markers to screen
    map.fitBounds(bounds, {zoom: {animate: true}});
    map.closePopup();
    selectedMarker = null;
  }

  /**
   * Draw map object and markers
   * @param {object} organisationList Object containing organisation and map data
   */
  function draw(organisationList) {
    var me = $(this);
    var organisations = organisationList;

    var layer = L.tileLayer(mapTileUrl, {
      attribution: attribution + ' Map data &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="http://openstreetmap.org">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/4.0/">CC-BY</a>',
      tileSize: 256
    });

    map = L.map($(holder).attr('id'), {
      zoomControl: false,
      layers: layer,
      minZoom: zoomLevel.far,
      maxZoom: 18,
      zoomSnap: 0.1,
      closePopupOnClick: false
    });

    finna.map.initMapZooming(map);

    // Center popup
    map.on('popupopen', function onPopupOpen(e) {
      map.setZoom(zoomLevel.close, {animate: false});

      var px = map.project(e.popup._latlng);
      px.y -= e.popup._container.clientHeight / 2;
      map.panTo(map.unproject(px), {animate: false});
    });

    map.on('popupclose', function onPopupClose(/*e*/) {
      selectedMarker = null;
    });

    L.control.locate({strings: {title: VuFind.translate('map_my_location')}}).addTo(map);
    $('.leaflet-control-locate a').attr('aria-label', VuFind.translate('map_my_location'));
    map.attributionControl.setPrefix('');
    var icons = {};
    $(['open', 'closed', 'no-schedule']).each(function addIcon(ind, obj) {
      icons[obj] = L.divIcon({
        className: 'mapMarker',
        iconSize: null,
        html: '<div class="leaflet-marker-icon leaflet-zoom-animated leaflet-interactive">' + VuFind.icon('map-marker', 'map-marker-icon ' + obj) + '</div>',
        iconAnchor: [10, 35],
        popupAnchor: [0, -36],
        labelAnchor: [-5, -86]
      });
    });

    // Map points
    $.each(organisations, function mapOrganisation(ind, obj) {
      var infoWindowContent = obj.map.info;

      var icon = icons['no-schedule'];
      if (obj.hasSchedules) {
        icon = obj.openNow ? icons.open : icons.closed;
      }

      var marker = L.marker(
        [obj.lat, obj.lon],
        {
          icon: icon,
          keyboard: false
        }
      ).addTo(map);
      marker.on('mouseover', function onMouseOverMarker(ev) {
        if (marker === selectedMarker) {
          return;
        }
        var holderOffset = $(holder).offset();
        var offset = $(ev.originalEvent.target).offset();
        var x = offset.left - holderOffset.left;
        var y = offset.top - holderOffset.top;

        me.trigger('marker-mouseover', {id: obj.id, x: x, y: y});
      });

      marker.on('mouseout', function onMouseOutMarker(/*ev*/) {
        me.trigger('marker-mouseout');
      });

      marker.on('click', function onClickMarker(/*ev*/) {
        me.trigger('marker-click', obj.id);
      });

      marker
        .bindPopup(infoWindowContent, {zoomAnimation: true, autoPan: false})
        .addTo(map);

      mapMarkers[obj.id] = marker;
      markers.push(marker);
    });

    reset();
  }

  /**
   * Resize handler for map
   */
  function resize() {
    map.invalidateSize(true);
  }

  /**
   * Hide marker
   */
  function hideMarker() {
    if (selectedMarker) {
      selectedMarker.closePopup();
    }
  }

  /**
   * Select a marker handler
   * @param {string} id Marker id to select
   */
  function selectMarker(id) {
    var marker = null;
    if (id in mapMarkers) {
      marker = mapMarkers[id];
    }
    if (selectedMarker) {
      if (selectedMarker === marker) {
        return;
      } else if (!marker) {
        hideMarker();
        return;
      }
    }

    if (marker) {
      marker.openPopup();
    }
    selectedMarker = marker;
  }

  /**
   * Init organisation map
   * @param {jQuery} _holder Container of the map elements
   * @param {string} _mapTileUrl Url to fetch map tiles from
   * @param {string} _attribution Map data attribution prefix
   */
  function init(_holder, _mapTileUrl, _attribution) {
    holder = _holder;
    mapTileUrl = _mapTileUrl;
    attribution = _attribution;
  }

  var my = {
    hideMarker: hideMarker,
    reset: reset,
    resize: resize,
    selectMarker: selectMarker,
    init: init,
    draw: draw
  };

  return my;
})();
