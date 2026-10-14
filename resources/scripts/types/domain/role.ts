export interface Ability {
  id: number
  name: string
  title: string | null
  entity_id: number | null
  entity_type: string | null
  only_owned: boolean
  scope: number | null
  created_at: string
  updated_at: string
}

export interface Role {
  id: number
  name: string
  title: string | null
  level: number | null
  formatted_created_at: string
  scope?: number | null
  abilities: Ability[]
}

export interface AbilityDefinition {
  name: string
  ability: string
  model?: string | null
  depends_on?: string[] | null
}
