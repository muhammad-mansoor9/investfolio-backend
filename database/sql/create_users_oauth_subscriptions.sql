-- PostgreSQL: Users, OAuth, Subscription Tables
-- Database: psx_db | Owner: psx_user
-- Execute with: psql -U psx_user -d psx_db -f create_users_oauth_subscriptions.sql

CREATE TABLE public.users (
    id uuid NOT NULL,
    google_id character varying(255),
    provider character varying(150) DEFAULT 'email'::character varying,
    name character varying(150) NOT NULL,
    email character varying(255) NOT NULL,
    image character varying(255),
    password character varying(255),
    is_active boolean DEFAULT true,
    preferences jsonb,
    remember_token character varying(150),
    email_verified_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT users_pkey PRIMARY KEY (id),
    CONSTRAINT users_email_key UNIQUE (email)
);

ALTER TABLE public.users OWNER TO psx_user;

CREATE INDEX idx_users_email ON public.users(email);
CREATE INDEX idx_users_provider ON public.users(provider);
CREATE INDEX idx_users_is_active ON public.users(is_active);

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON public.users
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();

-- OAuth Clients

CREATE TABLE public.oauth_clients (
    id uuid NOT NULL,
    user_id uuid,
    name character varying(255) NOT NULL,
    secret character varying(100),
    provider character varying(255),
    redirect text NOT NULL,
    personal_access_client boolean DEFAULT false NOT NULL,
    password_client boolean DEFAULT false NOT NULL,
    revoked boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT oauth_clients_pkey PRIMARY KEY (id),
    CONSTRAINT fk_oauth_clients_user_id FOREIGN KEY (user_id)
        REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE
);

ALTER TABLE public.oauth_clients OWNER TO psx_user;

CREATE INDEX idx_oauth_clients_user_id ON public.oauth_clients(user_id);
CREATE INDEX idx_oauth_clients_personal_access_client ON public.oauth_clients(personal_access_client);

CREATE TRIGGER update_oauth_clients_updated_at BEFORE UPDATE ON public.oauth_clients
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();

-- OAuth Access Tokens

CREATE TABLE public.oauth_access_tokens (
    id uuid NOT NULL,
    client_id uuid NOT NULL,
    user_id uuid,
    name character varying(255),
    scopes text,
    revoked boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    expires_at timestamp with time zone,
    CONSTRAINT oauth_access_tokens_pkey PRIMARY KEY (id),
    CONSTRAINT fk_oauth_access_tokens_client_id FOREIGN KEY (client_id)
        REFERENCES public.oauth_clients(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id)
        REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE
);

ALTER TABLE public.oauth_access_tokens OWNER TO psx_user;

CREATE INDEX idx_oauth_access_tokens_user_id ON public.oauth_access_tokens(user_id);
CREATE INDEX idx_oauth_access_tokens_client_id ON public.oauth_access_tokens(client_id);
CREATE INDEX idx_oauth_access_tokens_revoked ON public.oauth_access_tokens(revoked);

-- OAuth Refresh Tokens

CREATE TABLE public.oauth_refresh_tokens (
    id uuid NOT NULL,
    access_token_id uuid NOT NULL,
    revoked boolean DEFAULT false NOT NULL,
    expires_at timestamp with time zone,
    CONSTRAINT oauth_refresh_tokens_pkey PRIMARY KEY (id),
    CONSTRAINT fk_oauth_refresh_tokens_access_token_id FOREIGN KEY (access_token_id)
        REFERENCES public.oauth_access_tokens(id) ON UPDATE CASCADE ON DELETE CASCADE
);

ALTER TABLE public.oauth_refresh_tokens OWNER TO psx_user;

CREATE INDEX idx_oauth_refresh_tokens_access_token_id ON public.oauth_refresh_tokens(access_token_id);
CREATE INDEX idx_oauth_refresh_tokens_revoked ON public.oauth_refresh_tokens(revoked);

-- Subscription Plans

CREATE TABLE public.subscription_plans (
    id uuid NOT NULL,
    name character varying(150) NOT NULL,
    slug character varying(150) NOT NULL,
    description text,
    plan_type character varying(100) NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT subscription_plans_pkey PRIMARY KEY (id),
    CONSTRAINT subscription_plans_slug_key UNIQUE (slug),
    CONSTRAINT subscription_plans_chk_1 CHECK (jsonb_typeof(metadata) = 'object'::text)
);

ALTER TABLE public.subscription_plans OWNER TO psx_user;

CREATE INDEX idx_subscription_plans_is_active ON public.subscription_plans(is_active);
CREATE INDEX idx_subscription_plans_slug ON public.subscription_plans(slug);

CREATE TRIGGER update_subscription_plans_updated_at BEFORE UPDATE ON public.subscription_plans
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();

-- Subscription Plan Prices

CREATE TABLE public.subscription_plan_prices (
    id uuid NOT NULL,
    plan_id uuid NOT NULL,
    billing_cycle character varying(50) NOT NULL,
    amount numeric(12, 2) NOT NULL,
    currency character varying(3) DEFAULT 'PKR'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT subscription_plan_prices_pkey PRIMARY KEY (id),
    CONSTRAINT subscription_plan_prices_plan_id_billing_cycle_key UNIQUE(plan_id, billing_cycle),
    CONSTRAINT fk_subscription_plan_prices_plan_id FOREIGN KEY (plan_id)
        REFERENCES public.subscription_plans(id) ON UPDATE CASCADE ON DELETE CASCADE
);

ALTER TABLE public.subscription_plan_prices OWNER TO psx_user;

CREATE INDEX idx_subscription_plan_prices_plan_id ON public.subscription_plan_prices(plan_id);

CREATE TRIGGER update_subscription_plan_prices_updated_at BEFORE UPDATE ON public.subscription_plan_prices
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();

-- User Subscriptions

CREATE TABLE public.user_subscriptions (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    status character varying(50) DEFAULT 'active'::character varying NOT NULL,
    billing_cycle character varying(50) NOT NULL,
    amount numeric(12, 2) NOT NULL,
    currency character varying(3) DEFAULT 'PKR'::character varying NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    auto_renew boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT user_subscriptions_pkey PRIMARY KEY (id),
    CONSTRAINT fk_user_subscriptions_user_id FOREIGN KEY (user_id)
        REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_subscriptions_plan_id FOREIGN KEY (plan_id)
        REFERENCES public.subscription_plans(id) ON UPDATE RESTRICT ON DELETE RESTRICT
);

ALTER TABLE public.user_subscriptions OWNER TO psx_user;

CREATE INDEX idx_user_subscriptions_user_id ON public.user_subscriptions(user_id);
CREATE INDEX idx_user_subscriptions_plan_id ON public.user_subscriptions(plan_id);
CREATE INDEX idx_user_subscriptions_status ON public.user_subscriptions(status);
CREATE INDEX idx_user_subscriptions_active ON public.user_subscriptions(user_id, status)
    WHERE status = 'active'::character varying;

CREATE TRIGGER update_user_subscriptions_updated_at BEFORE UPDATE ON public.user_subscriptions
    FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();

-- Subscription History

CREATE TABLE public.subscription_history (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    action character varying(50) NOT NULL,
    old_plan_id uuid,
    new_plan_id uuid,
    old_status character varying(50),
    new_status character varying(50),
    old_ends_at timestamp with time zone,
    new_ends_at timestamp with time zone,
    reason text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp with time zone DEFAULT now(),
    CONSTRAINT subscription_history_pkey PRIMARY KEY (id),
    CONSTRAINT fk_subscription_history_user_id FOREIGN KEY (user_id)
        REFERENCES public.users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_subscription_history_subscription_id FOREIGN KEY (subscription_id)
        REFERENCES public.user_subscriptions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_subscription_history_old_plan_id FOREIGN KEY (old_plan_id)
        REFERENCES public.subscription_plans(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_history_new_plan_id FOREIGN KEY (new_plan_id)
        REFERENCES public.subscription_plans(id) ON UPDATE RESTRICT ON DELETE RESTRICT
);

ALTER TABLE public.subscription_history OWNER TO psx_user;

CREATE INDEX idx_subscription_history_user_id ON public.subscription_history(user_id);
CREATE INDEX idx_subscription_history_subscription_id ON public.subscription_history(subscription_id);
CREATE INDEX idx_subscription_history_action ON public.subscription_history(action);
CREATE INDEX idx_subscription_history_created_at ON public.subscription_history(created_at DESC);

GRANT ALL ON ALL TABLES IN SCHEMA public TO psx_user;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO psx_user;
