// Libellés FR et icônes des énumérations véhicule (VehicleType / VehicleEnergy côté back).

export const VEHICLE_TYPE_LABELS = {
    car: 'Voiture',
    motorcycle: 'Moto',
    bike: 'Vélo',
    other: 'Autre',
};

export const VEHICLE_TYPE_OPTIONS = [
    { label: 'Voiture', value: 'car' },
    { label: 'Moto', value: 'motorcycle' },
    { label: 'Vélo', value: 'bike' },
    { label: 'Autre', value: 'other' },
];

// PrimeIcons n'a ni moto ni vélo : compteur pour la moto, drapeau pour le vélo.
export const VEHICLE_TYPE_ICONS = {
    car: 'pi pi-car',
    motorcycle: 'pi pi-gauge',
    bike: 'pi pi-flag',
    other: 'pi pi-tag',
};

export const VEHICLE_ENERGY_LABELS = {
    petrol: 'Essence',
    diesel: 'Diesel',
    electric: 'Électrique',
    hybrid: 'Hybride',
    plugin_hybrid: 'Hybride rechargeable',
    lpg: 'GPL',
    other: 'Autre',
};

export const VEHICLE_ENERGY_OPTIONS = [
    { label: 'Essence', value: 'petrol' },
    { label: 'Diesel', value: 'diesel' },
    { label: 'Électrique', value: 'electric' },
    { label: 'Hybride', value: 'hybrid' },
    { label: 'Hybride rechargeable', value: 'plugin_hybrid' },
    { label: 'GPL', value: 'lpg' },
    { label: 'Autre', value: 'other' },
];

// Libellés des sources d'affectation d'une opération à un véhicule.
export const VEHICLE_SOURCE_LABELS = {
    manual: 'Affectation manuelle',
    rule: 'Affectation par règle',
    auto: 'Affectation automatique',
};

export const VEHICLE_SOURCE_ICONS = {
    manual: 'pi pi-user',
    rule: 'pi pi-bolt',
    auto: 'pi pi-cog',
};
