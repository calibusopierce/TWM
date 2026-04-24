USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[JobApplicationsInterview]    Script Date: 4/10/2026 2:59:11 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[JobApplicationsInterview](
	[InterviewID] [int] IDENTITY(1,1) NOT NULL,
	[ApplicationID] [int] NOT NULL,
	[InterviewDateTime] [datetime] NOT NULL,
	[OfficeAddress] [nvarchar](255) NOT NULL,
	[HRContactFileNo] [int] NOT NULL,
	[CreatedAt] [datetime] NULL,
	[ModifiedAt] [datetime] NULL,
	[InterviewType] [tinyint] NOT NULL,
	[RescheduleRefID] [int] NULL,
PRIMARY KEY CLUSTERED 
(
	[InterviewID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO

ALTER TABLE [dbo].[JobApplicationsInterview] ADD  DEFAULT (getdate()) FOR [CreatedAt]
GO

ALTER TABLE [dbo].[JobApplicationsInterview] ADD  CONSTRAINT [DF_JobApplicationsInterview_ModifiedAt]  DEFAULT (getdate()) FOR [ModifiedAt]
GO

ALTER TABLE [dbo].[JobApplicationsInterview] ADD  CONSTRAINT [DF_JobApplicationsInterview_InterviewType]  DEFAULT ((1)) FOR [InterviewType]
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([ApplicationID])
REFERENCES [dbo].[JobApplications] ([ApplicationID])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([ApplicationID])
REFERENCES [dbo].[JobApplications] ([ApplicationID])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([ApplicationID])
REFERENCES [dbo].[JobApplications] ([ApplicationID])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([HRContactFileNo])
REFERENCES [dbo].[TBL_HREmployeeList] ([FileNo])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([HRContactFileNo])
REFERENCES [dbo].[TBL_HREmployeeList] ([FileNo])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([HRContactFileNo])
REFERENCES [dbo].[TBL_HREmployeeList] ([FileNo])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([HRContactFileNo])
REFERENCES [dbo].[TBL_HREmployeeList] ([FileNo])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD FOREIGN KEY([HRContactFileNo])
REFERENCES [dbo].[TBL_HREmployeeList] ([FileNo])
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD  CONSTRAINT [FK_JobApplicationsInterview_RescheduleRef] FOREIGN KEY([RescheduleRefID])
REFERENCES [dbo].[JobApplicationsInterview] ([InterviewID])
GO

ALTER TABLE [dbo].[JobApplicationsInterview] CHECK CONSTRAINT [FK_JobApplicationsInterview_RescheduleRef]
GO

ALTER TABLE [dbo].[JobApplicationsInterview]  WITH CHECK ADD  CONSTRAINT [CHK_JobApplicationsInterview_InterviewType] CHECK  (([InterviewType]=(2) OR [InterviewType]=(1)))
GO

ALTER TABLE [dbo].[JobApplicationsInterview] CHECK CONSTRAINT [CHK_JobApplicationsInterview_InterviewType]
GO


