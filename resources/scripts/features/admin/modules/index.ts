export { adminModuleRoutes } from './routes'

export { useModuleStore } from './store'
export type { ModuleDetailResponse, ModuleDetailMeta } from '../../../api/services/module.service'
export type {
  ModuleState,
  ModuleStore,
  InstallationStep,
} from './store'

// Views
export { default as ModuleIndexView } from './views/ModuleIndexView.vue'
export { default as ModuleDetailView } from './views/ModuleDetailView.vue'

// Components
export { default as ModuleCard } from './components/ModuleCard.vue'
