(() => {
  const autocompleteSelector = 'input[data-google-autocomplete="address"]';

  const fieldSelectors = [
    {key: 'city', types: ['locality', 'postal_town', 'administrative_area_level_2']},
    {key: 'state', types: ['administrative_area_level_1']},
    {key: 'country', types: ['country']},
    {key: 'postal', types: ['postal_code']},
  ];

  const findTarget = (selector) => {
    if (!selector) {
      return null;
    }
    try {
      if (selector.startsWith('#')) {
        return document.querySelector(selector);
      }
      return document.querySelector(selector.replace(/\\[/g, '\\\\[').replace(/\\]/g, '\\\\]'));
    } catch (err) {
      return document.querySelector(selector);
    }
  };

  const setValue = (input, selector, value) => {
    if (!selector || !value) {
      return;
    }
    const target = findTarget(selector);
    if (!target) {
      return;
    }
    const normalizedValue = value.trim();
    if (target.tagName === 'SELECT') {
      const match = Array.from(target.options).find((option) => {
        const candidate = (option.textContent || option.value || '').trim().toLowerCase();
        return candidate === normalizedValue.toLowerCase() || option.value.toLowerCase() === normalizedValue.toLowerCase();
      });
      target.value = match ? match.value : normalizedValue;
      target.dispatchEvent(new Event('change', {bubbles: true}));
    } else {
      target.value = normalizedValue;
      target.dispatchEvent(new Event('input', {bubbles: true}));
    }
  };

  const extractComponents = (place) => {
    const components = {};
    (place.address_components || []).forEach((component) => {
      component.types.forEach((type) => {
        components[type] = component.long_name;
        components[`${type}_short`] = component.short_name;
      });
    });
    return components;
  };

  const resolveComponent = (components, candidateTypes) => {
    for (const type of candidateTypes) {
      if (!components[type]) {
        continue;
      }
      return components[type];
    }
    return null;
  };

  const attachAutocomplete = (input) => {
    if (input.dataset.googleAutocompleteAttached === '1') {
      return;
    }
    const options = {
      fields: ['address_components'],
      componentRestrictions: {country: []},
    };
    const autocomplete = new google.maps.places.Autocomplete(input, options);
    autocomplete.addListener('place_changed', () => {
      const place = autocomplete.getPlace();
      if (!place || !place.address_components) {
        return;
      }
      const components = extractComponents(place);
      fieldSelectors.forEach(({key, types}) => {
        const selector = input.dataset[`googleAutocomplete${key.charAt(0).toUpperCase() + key.slice(1)}`];
        const value = resolveComponent(components, types);
        setValue(input, selector, value);
      });
    });
    input.dataset.googleAutocompleteAttached = '1';
  };

  const init = () => {
    if (!window.google?.maps?.places) {
      return;
    }
    document.querySelectorAll(autocompleteSelector).forEach(attachAutocomplete);
  };

  window.goldwingAddressAutocompleteInit = () => {
    init();
  };

  document.addEventListener('DOMContentLoaded', () => {
    init();
  });
})();
