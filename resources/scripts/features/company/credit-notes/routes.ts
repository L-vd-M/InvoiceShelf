import type { RouteRecordRaw } from 'vue-router'

const CreditNoteIndexView = () => import('./views/CreditNoteIndexView.vue')

export const creditNoteRoutes: RouteRecordRaw[] = [
  {
    path: 'credit-notes',
    name: 'credit-notes.index',
    component: CreditNoteIndexView,
    meta: {
      requiresAuth: true,
      ability: 'view-invoice',
      title: 'credit_notes.title',
    },
  },
]
