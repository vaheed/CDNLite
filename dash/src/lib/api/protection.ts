import { api } from './client';
import type { ApiProtectionDiscovery, OnboardingAnswers, OnboardingApplyResult, OnboardingPreview, OnboardingState, ProtectionIntentMutationResult, ProtectionIntentPreview, ProtectionIntentSummary, ProtectionProfileMutationResult, ProtectionProfilePreview, ProtectionProfileSummary } from '@/types';

export const protectionApi = {
  listProfiles: (domainId: string) => api.get<ProtectionProfileSummary[]>(`/api/v1/domains/${domainId}/protection/profiles`),
  previewProfile: (domainId: string, profileKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfilePreview>(`/api/v1/domains/${domainId}/protection/profiles/${profileKey}/preview`, input),
  applyProfile: (domainId: string, profileKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfileMutationResult>(`/api/v1/domains/${domainId}/protection/profiles/${profileKey}/apply`, input),
  disableProfile: (domainId: string, profileId: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionProfileMutationResult>(`/api/v1/domains/${domainId}/protection/profiles/${profileId}/disable`, input),
  discoverApiPaths: (domainId: string) =>
    api.get<ApiProtectionDiscovery>(`/api/v1/domains/${domainId}/protection/api-paths`),
  listIntents: (domainId: string) => api.get<ProtectionIntentSummary[]>(`/api/v1/domains/${domainId}/protection/intents`),
  previewIntent: (domainId: string, intentKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentPreview>(`/api/v1/domains/${domainId}/protection/intents/${intentKey}/preview`, input),
  enableIntent: (domainId: string, intentKey: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentKey}/enable`, input),
  disableIntent: (domainId: string, intentId: string, input: Record<string, unknown> = {}) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentId}/disable`, input),
  undoIntent: (domainId: string, intentId: string) =>
    api.post<ProtectionIntentMutationResult>(`/api/v1/domains/${domainId}/protection/intents/${intentId}/undo`),
  getOnboarding: (domainId: string) => api.get<OnboardingState>(`/api/v1/domains/${domainId}/onboarding`),
  saveOnboardingAnswers: (domainId: string, answers: OnboardingAnswers) =>
    api.post<OnboardingState>(`/api/v1/domains/${domainId}/onboarding/answers`, { answers }),
  previewOnboarding: (domainId: string) =>
    api.post<OnboardingPreview>(`/api/v1/domains/${domainId}/onboarding/preview`),
  applyOnboarding: (domainId: string) =>
    api.post<OnboardingApplyResult>(`/api/v1/domains/${domainId}/onboarding/apply`),
  skipOnboarding: (domainId: string) =>
    api.post<OnboardingState>(`/api/v1/domains/${domainId}/onboarding/skip`),
  resumeOnboarding: (domainId: string) =>
    api.post<OnboardingState>(`/api/v1/domains/${domainId}/onboarding/resume`),
};
