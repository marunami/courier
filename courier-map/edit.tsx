/* global google */

import React from 'react';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
  InspectorControls,
  useBlockProps,
} from '@wordpress/block-editor';
import {
  Button,
  Card,
  CardBody,
  ColorPalette,
  PanelBody,
  RangeControl,
  SelectControl,
  TextareaControl,
  TextControl,
} from '@wordpress/components';
import { BlockEditProps } from '@wordpress/blocks';

import './index.scss';

const DAY_ORDER = [
  'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
];

const PRICE_LEVEL_MAP: Record<string, string> = {
  FREE: '',
  INEXPENSIVE: '$',
  MODERATE: '$$',
  EXPENSIVE: '$$$',
  VERY_EXPENSIVE: '$$$$',
};

function mapPriceLevel(priceLevel: unknown): string {
  if (!priceLevel) return '';
  const key = String(priceLevel).toUpperCase().replace('PRICE_LEVEL_', '');
  return PRICE_LEVEL_MAP[key] ?? '';
}

interface MapCategory {
  key: string;
  label: string;
  color: string;
}

interface MapLocation {
  id: string;
  title: string;
  address: string;
  lat: number;
  lng: number;
  place_id: string;
  phone: string;
  url: string;
  description: string;
  category: string;
  rating: number;
  price: string;
  hours: Record<string, string>;
}

interface Attributes {
  locations: MapLocation[];
  categories: MapCategory[];
  mapId: string;
  accentColor: string;
  height: number;
  zoom: number;
  panelTitle: string;
  panelTagline: string;
}

const generateId = () => `loc_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;

const slugify = (value: string) => value
  .toLowerCase()
  .trim()
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

const emptyHours = (): Record<string, string> => {
  const hours: Record<string, string> = {};
  DAY_ORDER.forEach((day) => {
    hours[day] = '';
  });
  return hours;
};

const emptyLocation = (): MapLocation => ({
  id: generateId(),
  title: '',
  address: '',
  lat: 0,
  lng: 0,
  place_id: '',
  phone: '',
  url: '',
  description: '',
  category: '',
  rating: 0,
  price: '',
  hours: emptyHours(),
});

function waitForGoogleMaps(timeout = 10000): Promise<void> {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    const check = () => {
      if (window.google?.maps?.importLibrary) {
        resolve();
        return;
      }
      if (Date.now() - started >= timeout) {
        reject(new Error('Google Maps API loader did not become available.'));
        return;
      }
      window.setTimeout(check, 100);
    };
    check();
  });
}

function locationLabel(location: MapLocation): string {
  if (location.title) return location.title;
  if (location.address) return location.address;
  return __('(untitled location)');
}

function AddressField({
  address,
  lat,
  lng,
  onChange,
}: {
  address: string;
  lat: number;
  lng: number;
  onChange: (fields: Partial<MapLocation>) => void;
}) {
  const [status, setStatus] = useState<'idle' | 'loading' | 'error'>('idle');
  const [errorMessage, setErrorMessage] = useState('');

  const handleAutofill = () => {
    const trimmedAddress = address.trim();
    if (!trimmedAddress) {
      setStatus('error');
      setErrorMessage(__('Enter an address first.'));
      return;
    }

    setStatus('loading');
    setErrorMessage('');

    waitForGoogleMaps()
      .then(async () => {
        const placesLibrary = (await google.maps.importLibrary(
          'places',
        )) as google.maps.PlacesLibrary;
        const { Place } = placesLibrary;

        const { places } = await Place.searchByText({
          textQuery: trimmedAddress,
          fields: [
            'displayName',
            'formattedAddress',
            'location',
            'id',
            'internationalPhoneNumber',
            'websiteURI',
            'rating',
            'priceLevel',
            'editorialSummary',
            'regularOpeningHours',
          ],
          maxResultCount: 1,
        });

        const place = places?.[0];
        if (!place) {
          throw new Error(__('Google did not return a result for this address.'));
        }

        const fields: Partial<MapLocation> = {
          address: place.formattedAddress ?? trimmedAddress,
          lat: place.location?.lat() ?? 0,
          lng: place.location?.lng() ?? 0,
          place_id: place.id ?? '',
        };

        if (place.displayName) fields.title = place.displayName;
        if (place.internationalPhoneNumber) fields.phone = place.internationalPhoneNumber;
        if (place.websiteURI) fields.url = place.websiteURI;
        if (typeof place.rating === 'number') fields.rating = place.rating;
        if (place.priceLevel) fields.price = mapPriceLevel(place.priceLevel);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const summary = (place as any).editorialSummary;
        if (summary?.text) {
          fields.description = summary.text;
        } else if (typeof summary === 'string' && summary) {
          fields.description = summary;
        }

        const weekdayDescriptions = place.regularOpeningHours?.weekdayDescriptions;
        if (weekdayDescriptions?.length) {
          const parsedHours: Record<string, string> = {};
          weekdayDescriptions.forEach((line) => {
            const separatorIndex = line.indexOf(': ');
            if (separatorIndex === -1) return;
            const day = line.slice(0, separatorIndex).trim();
            const hoursText = line.slice(separatorIndex + 2).trim();
            if (DAY_ORDER.includes(day)) {
              parsedHours[day] = hoursText;
            }
          });
          if (Object.keys(parsedHours).length) {
            fields.hours = { ...emptyHours(), ...parsedHours };
          }
        }

        onChange(fields);
        setStatus('idle');
        setErrorMessage('');
      })
      .catch((error: unknown) => {
        setStatus('error');
        setErrorMessage(
          error instanceof Error ? error.message : __('Could not find this location.'),
        );
      });
  };

  const handleAddressChange = (value: string) => {
    onChange({
      address: value, lat: 0, lng: 0, place_id: '',
    });
    if (status === 'error') {
      setStatus('idle');
      setErrorMessage('');
    }
  };

  return (
    <div style={{ marginBottom: 12 }}>
      <TextControl
        label={__('Address')}
        value={address}
        onChange={handleAddressChange}
        placeholder={__('123 Main St, City, State ZIP — or a business name')}
      />
      <Button
        variant="secondary"
        onClick={handleAutofill}
        isBusy={status === 'loading'}
        disabled={status === 'loading'}
      >
        {status === 'loading' ? __('Finding location…') : __('Autofill from address')}
      </Button>
      {lat !== 0 || lng !== 0 ? (
        <p style={{ fontSize: 12, color: '#666', marginTop: 4 }}>
          {__('Coordinates:')}
          {' '}
          {lat.toFixed(5)}
          ,
          {' '}
          {lng.toFixed(5)}
        </p>
      ) : null}
      {status === 'error' ? (
        <p style={{ fontSize: 12, color: '#c0121f', marginTop: 4 }}>{errorMessage}</p>
      ) : null}
    </div>
  );
}

interface CategoryManagerProps {
  categories: MapCategory[];
  onChange: (categories: MapCategory[]) => void;
}

function CategoryManager({ categories, onChange }: CategoryManagerProps) {
  const updateCategory = (index: number, fields: Partial<MapCategory>) => {
    const next = categories.map((cat, i) => (i === index ? { ...cat, ...fields } : cat));
    onChange(next);
  };

  const removeCategory = (index: number) => {
    onChange(categories.filter((_, i) => i !== index));
  };

  const addCategory = () => {
    const label = __('New category');
    onChange([...categories, { key: `${slugify(label)}-${generateId()}`, label, color: '#1c86c4' }]);
  };

  return (
    <div>
      {categories.map((cat, index) => (
        <Card key={cat.key} style={{ marginBottom: 8 }}>
          <CardBody>
            <TextControl
              label={__('Label')}
              value={cat.label}
              onChange={(label) => updateCategory(index, { label })}
            />
            <p style={{ marginBottom: 4 }}>{__('Color')}</p>
            <ColorPalette
              value={cat.color}
              onChange={(color) => updateCategory(index, { color: color ?? '#1c86c4' })}
            />
            <Button variant="tertiary" isDestructive onClick={() => removeCategory(index)}>
              {__('Remove category')}
            </Button>
          </CardBody>
        </Card>
      ))}
      <Button variant="secondary" onClick={addCategory}>
        {__('+ Add Category')}
      </Button>
    </div>
  );
}

interface HoursEditorProps {
  hours: Record<string, string>;
  onChange: (hours: Record<string, string>) => void;
}

function HoursEditor({ hours, onChange }: HoursEditorProps) {
  return (
    <div>
      <p style={{ marginBottom: 4, fontWeight: 600 }}>{__('Hours (optional, one per day)')}</p>
      {DAY_ORDER.map((day) => (
        <TextControl
          key={day}
          label={day}
          value={hours[day] ?? ''}
          placeholder={__('e.g. 9:00 AM – 5:00 PM, or Closed')}
          onChange={(value) => onChange({ ...hours, [day]: value })}
        />
      ))}
    </div>
  );
}

function LocationRow({
  location,
  categories,
  onChange,
  onRemove,
}: {
  location: MapLocation;
  categories: MapCategory[];
  onChange: (updated: MapLocation) => void;
  onRemove: () => void;
}) {
  const [isOpen, setIsOpen] = useState(!location.address);
  const [showMore, setShowMore] = useState(false);

  const category = categories.find((cat) => cat.key === location.category);
  const categoryOptions = [
    { label: __('(none)'), value: '' },
    ...categories.map((cat) => ({ label: cat.label, value: cat.key })),
  ];

  if (!isOpen) {
    return (
      <Card style={{ marginBottom: 8 }}>
        <CardBody
          style={{
            display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer',
          }}
          onClick={() => setIsOpen(true)}
        >
          <div style={{
            width: 28,
            height: 28,
            flexShrink: 0,
            background: category ? category.color : '#1c86c4',
            color: '#fff',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontWeight: 700,
            fontSize: 13,
          }}
          >
            {locationLabel(location).charAt(0).toUpperCase()}
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <p style={{ margin: 0, fontWeight: 600, fontSize: 13 }}>{locationLabel(location)}</p>
            {location.rating ? (
              <p style={{ margin: 0, fontSize: 11, color: '#666' }}>
                {'★ '}
                {location.rating.toFixed(1)}
              </p>
            ) : null}
          </div>
          <span style={{ fontSize: 16 }}>▸</span>
        </CardBody>
      </Card>
    );
  }

  return (
    <Card style={{ marginBottom: 12 }}>
      <CardBody>
        <div style={{
          display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8,
        }}
        >
          <Button variant="tertiary" onClick={() => setIsOpen(false)}>
            {__('▾ Collapse')}
          </Button>
        </div>

        <AddressField
          address={location.address}
          lat={location.lat}
          lng={location.lng}
          onChange={(fields) => onChange({ ...location, ...fields })}
        />
        <TextControl
          label={__('Title')}
          value={location.title}
          onChange={(title) => onChange({ ...location, title })}
        />

        <Button
          variant="link"
          onClick={() => setShowMore(!showMore)}
          style={{ marginBottom: 8 }}
        >
          {showMore ? __('Hide more fields ▴') : __('Show more fields ▾')}
        </Button>

        {showMore ? (
          <>
            <SelectControl
              label={__('Category')}
              value={location.category}
              options={categoryOptions}
              onChange={(cat) => onChange({ ...location, category: cat })}
            />
            <TextControl
              label={__('Rating (0–5, optional)')}
              type="number"
              min={0}
              max={5}
              step={0.1}
              value={location.rating ? String(location.rating) : ''}
              onChange={(value) => onChange({ ...location, rating: Number(value) || 0 })}
            />
            <TextControl
              label={__('Price (optional, e.g. $ or $$)')}
              value={location.price}
              onChange={(price) => onChange({ ...location, price })}
            />
            <TextControl
              label={__('Phone (optional)')}
              value={location.phone}
              onChange={(phone) => onChange({ ...location, phone })}
            />
            <TextControl
              label={__('Website (optional)')}
              value={location.url}
              onChange={(url) => onChange({ ...location, url })}
            />
            <TextareaControl
              label={__('Description (optional — shown in the detail panel only)')}
              value={location.description}
              onChange={(description) => onChange({ ...location, description })}
            />
            <HoursEditor
              hours={location.hours ?? emptyHours()}
              onChange={(hours) => onChange({ ...location, hours })}
            />
          </>
        ) : null}

        <Button variant="tertiary" isDestructive onClick={onRemove}>
          {__('Remove location')}
        </Button>
      </CardBody>
    </Card>
  );
}

export default function Edit({ attributes, setAttributes }: BlockEditProps<Attributes>) {
  const {
    locations, categories, accentColor, mapId, height, zoom, panelTitle, panelTagline,
  } = attributes;

  const [showLocations, setShowLocations] = useState(false);

  const updateLocation = (updated: MapLocation) => {
    setAttributes({
      locations: locations.map((location) => (location.id === updated.id ? updated : location)),
    });
  };

  const removeLocation = (id: string) => {
    setAttributes({ locations: locations.filter((location) => location.id !== id) });
  };

  const addLocation = () => {
    setAttributes({ locations: [...locations, emptyLocation()] });
  };

  return (
    <div {...useBlockProps()}>
      <InspectorControls>
        <PanelBody title={__('Panel header')} initialOpen>
          <TextControl
            label={__('Panel title')}
            value={panelTitle}
            onChange={(value) => setAttributes({ panelTitle: value })}
          />
          <TextControl
            label={__('Panel tagline (optional)')}
            value={panelTagline}
            onChange={(value) => setAttributes({ panelTagline: value })}
          />
        </PanelBody>

        <PanelBody title={__('Categories')} initialOpen={false}>
          <CategoryManager
            categories={categories}
            onChange={(next) => setAttributes({ categories: next })}
          />
        </PanelBody>

        <PanelBody title={__('Locations')} initialOpen>
          {locations.map((location) => (
            <LocationRow
              key={location.id}
              location={location}
              categories={categories}
              onChange={updateLocation}
              onRemove={() => removeLocation(location.id)}
            />
          ))}
          <Button variant="secondary" onClick={addLocation}>
            {__('+ Add Location')}
          </Button>
        </PanelBody>

        <PanelBody title={__('Map style')} initialOpen={false}>
          <p>{__('Default marker color (used when a location has no category)')}</p>
          <ColorPalette
            value={accentColor}
            onChange={(value) => setAttributes({ accentColor: value ?? '#1c86c4' })}
          />
          <TextControl
            label={__('Map ID (Google Cloud Map ID, optional)')}
            value={mapId}
            onChange={(value) => setAttributes({ mapId: value })}
          />
          <RangeControl
            label={__('Map height (px)')}
            value={height}
            onChange={(value) => setAttributes({ height: value ?? 400 })}
            min={200}
            max={800}
          />
          <RangeControl
            label={__('Default zoom')}
            value={zoom}
            onChange={(value) => setAttributes({ zoom: value ?? 12 })}
            min={1}
            max={20}
          />
        </PanelBody>
      </InspectorControls>

      <div style={{
        border: '1px dashed #ccc', padding: 24, textAlign: 'center', background: '#fafafa',
      }}
      >
        <p>
          <strong>{__('Map block')}</strong>
          {' — '}
          {locations.length === 0
            ? __('No locations added yet.')
            : `${locations.length} location${locations.length === 1 ? '' : 's'} added.`}
        </p>
        <Button variant="link" onClick={() => setShowLocations(!showLocations)}>
          {__('Manage locations in the sidebar →')}
        </Button>
        {showLocations ? (
          <ul style={{ textAlign: 'left', marginTop: 12 }}>
            {locations.map((location) => (
              <li key={location.id}>{locationLabel(location)}</li>
            ))}
          </ul>
        ) : null}
      </div>
    </div>
  );
}
