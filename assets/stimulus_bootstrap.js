import { startStimulusApp } from '@symfony/stimulus-bundle';
import OnboardingController from './controllers/onboarding_controller.js';
import SearchablePickerController from './controllers/searchable_picker_controller.js';

const app = startStimulusApp();
app.register('onboarding', OnboardingController);
app.register('searchable-picker', SearchablePickerController);
